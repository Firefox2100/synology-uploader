// End-to-end test: real OIDC login (against the mock provider) and real tus-js-client
// uploads against the app running on PHP's built-in server. Start both with scripts/e2e.sh.
import { test, before } from 'node:test'
import assert from 'node:assert/strict'
import { createHash, randomBytes } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import * as tus from 'tus-js-client'

const BASE = process.env.E2E_BASE ?? 'http://127.0.0.1:18080/'
const DEST = process.env.E2E_DEST ?? '/tmp/synoup-e2e/dest'
const ORIGIN = new URL(BASE).origin
const ENDPOINT = new URL('upload.php', BASE).href
const CHUNK = 1024 * 1024

const cookies = new Map()

function cookieHeader() {
  return [...cookies].map(([k, v]) => `${k}=${v}`).join('; ')
}

async function request(url, init = {}) {
  const res = await fetch(url, {
    redirect: 'manual',
    ...init,
    headers: { Cookie: cookieHeader(), ...(init.headers ?? {}) },
  })
  for (const c of res.headers.getSetCookie()) {
    const [pair] = c.split(';')
    const [name, value] = pair.split('=')
    if (value === 'deleted' || value === '') cookies.delete(name)
    else cookies.set(name, value)
  }
  return res
}

let csrf

async function login() {
  let res = await request(new URL('auth.php', BASE))
  // app -> provider /authorize -> app /auth.php?code=... -> app /
  for (let i = 0; i < 5 && res.status >= 300 && res.status < 400; i++) {
    res = await request(new URL(res.headers.get('location'), res.url || BASE))
  }
  const page = await (await request(BASE)).text()
  const match = page.match(/<script type="application\/json" id="app-config">(.*?)<\/script>/s)
  assert.ok(match, 'upload page with config after login')
  const config = JSON.parse(match[1])
  assert.equal(config.user, 'alice')
  assert.equal(config.chunkSize, CHUNK)
  return config.csrfToken
}

function tusOptions(extra = {}) {
  return {
    endpoint: ENDPOINT,
    chunkSize: CHUNK,
    retryDelays: [0, 200, 500],
    headers: { Cookie: cookieHeader(), 'X-CSRF-Token': csrf, Origin: ORIGIN },
    ...extra,
  }
}

function upload(data, name, extra = {}) {
  return new Promise((resolve, reject) => {
    const u = new tus.Upload(data, tusOptions({
      metadata: { filename: name, filetype: 'application/octet-stream' },
      onError: reject,
      onSuccess: () => resolve(u),
      ...extra,
    }))
    u.start()
  })
}

const sha = (buf) => createHash('sha256').update(buf).digest('hex')

before(async () => {
  csrf = await login()
})

test('chunked upload arrives intact', async () => {
  const data = randomBytes(5 * CHUNK + 12345)
  const u = await upload(data, 'big file ü.bin')
  assert.match(u.url, /upload\.php\?id=[a-f0-9]{32}$/)
  assert.equal(sha(await readFile(`${DEST}/big file ü.bin`)), sha(data))
})

test('pause and resume continues from the server offset', async () => {
  const data = randomBytes(6 * CHUNK)
  const name = 'resumed.bin'
  let paused
  const url = await new Promise((resolve, reject) => {
    const u = new tus.Upload(data, tusOptions({
      metadata: { filename: name },
      onError: reject,
      onChunkComplete: (_size, sent) => {
        if (sent >= 2 * CHUNK && !paused) {
          paused = true
          u.abort().then(() => resolve(u.url))
        }
      },
    }))
    u.start()
  })

  const head = await request(url, { method: 'HEAD', headers: { 'Tus-Resumable': '1.0.0', 'X-CSRF-Token': csrf } })
  assert.equal(head.status, 200)
  const offset = Number(head.headers.get('upload-offset'))
  assert.ok(offset >= 2 * CHUNK && offset < data.length, `offset ${offset}`)

  let firstPatchOffset
  await upload(data, name, {
    uploadUrl: url,
    onBeforeRequest: (req) => {
      if (req.getMethod() === 'PATCH' && firstPatchOffset === undefined) {
        firstPatchOffset = Number(req.getHeader('Upload-Offset'))
      }
    },
  })
  assert.equal(firstPatchOffset, offset, 'resumed from server offset, not from 0')
  assert.equal(sha(await readFile(`${DEST}/${name}`)), sha(data))
})

test('same name twice never overwrites', async () => {
  const a = randomBytes(1000)
  const b = randomBytes(2000)
  await upload(a, 'dup.txt')
  await upload(b, 'dup.txt')
  assert.equal(sha(await readFile(`${DEST}/dup.txt`)), sha(a))
  assert.equal(sha(await readFile(`${DEST}/dup (1).txt`)), sha(b))
})

test('cancel terminates the upload on the server', async () => {
  const data = randomBytes(4 * CHUNK)
  const url = await new Promise((resolve, reject) => {
    const u = new tus.Upload(data, tusOptions({
      metadata: { filename: 'cancelled.bin' },
      onError: reject,
      onChunkComplete: () => {
        u.abort(true).then(() => resolve(u.url), reject)
      },
    }))
    u.start()
  })
  const head = await request(url, { method: 'HEAD', headers: { 'Tus-Resumable': '1.0.0', 'X-CSRF-Token': csrf } })
  assert.equal(head.status, 404)
})

test('requests without CSRF token, Origin or session are refused', async () => {
  const create = (headers) => fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Tus-Resumable': '1.0.0', 'Upload-Length': '1', 'Upload-Metadata': 'filename eA==', ...headers },
  })
  assert.equal((await create({ Cookie: cookieHeader(), Origin: ORIGIN })).status, 403, 'no csrf')
  assert.equal((await create({ Cookie: cookieHeader(), 'X-CSRF-Token': csrf })).status, 403, 'no origin')
  assert.equal((await create({ Cookie: cookieHeader(), 'X-CSRF-Token': csrf, Origin: 'https://evil.example' })).status, 403, 'bad origin')
  assert.equal((await create({ 'X-CSRF-Token': csrf, Origin: ORIGIN })).status, 401, 'no session')
  assert.equal((await create({ Cookie: cookieHeader(), 'X-CSRF-Token': csrf, Origin: ORIGIN, 'Sec-Fetch-Site': 'cross-site' })).status, 403, 'cross-site')
})

test('oversized chunk is rejected', async () => {
  const res = await request(ENDPOINT, {
    method: 'POST',
    headers: { 'Tus-Resumable': '1.0.0', 'Upload-Length': String(4 * CHUNK), 'Upload-Metadata': 'filename eC5iaW4=', 'X-CSRF-Token': csrf, Origin: ORIGIN },
  })
  assert.equal(res.status, 201)
  const url = new URL(res.headers.get('location'), BASE)
  const patch = await request(url, {
    method: 'PATCH',
    headers: { 'Tus-Resumable': '1.0.0', 'Upload-Offset': '0', 'Content-Type': 'application/offset+octet-stream', 'X-CSRF-Token': csrf, Origin: ORIGIN },
    body: randomBytes(3 * CHUNK),
  })
  assert.equal(patch.status, 413)
})

test('security headers are present', async () => {
  const res = await request(BASE)
  assert.match(res.headers.get('content-security-policy'), /default-src 'none'/)
  assert.equal(res.headers.get('x-content-type-options'), 'nosniff')
  assert.equal(res.headers.get('x-frame-options'), 'DENY')
})

test('logout ends the session', async () => {
  const body = new URLSearchParams({ csrf })
  const res = await request(new URL('auth.php?action=logout', BASE), {
    method: 'POST',
    headers: { Origin: ORIGIN, 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  })
  assert.equal(res.status, 303)
  // Single logout: the browser is sent to the provider's end_session_endpoint with the ID
  // token hint, and the provider sends it back to the app.
  const location = new URL(res.headers.get('location'))
  assert.equal(location.origin + location.pathname, 'http://127.0.0.1:18081/logout')
  assert.equal(location.searchParams.get('post_logout_redirect_uri'), BASE)
  assert.match(location.searchParams.get('id_token_hint') ?? '', /^[\w-]+\.[\w-]+\.[\w-]+$/)
  const back = await fetch(location, { redirect: 'manual' })
  assert.equal(back.headers.get('location'), BASE)
  const csp = res.headers.get('content-security-policy')
  assert.match(csp, /form-action 'self' http:\/\/127\.0\.0\.1:18081;/)
  const after = await request(ENDPOINT, {
    method: 'POST',
    headers: { 'Tus-Resumable': '1.0.0', 'Upload-Length': '1', 'Upload-Metadata': 'filename eA==', 'X-CSRF-Token': csrf, Origin: ORIGIN },
  })
  assert.equal(after.status, 401)
})
