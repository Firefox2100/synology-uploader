import Uppy from '@uppy/core'
import Dashboard from '@uppy/dashboard'
import Tus from '@uppy/tus'

import '@uppy/core/css/style.min.css'
import '@uppy/dashboard/css/style.min.css'
import './styles.css'

const config = JSON.parse(document.getElementById('app-config').textContent)
const notice = document.getElementById('notice')

function showNotice(message, kind = 'error') {
  notice.textContent = message
  notice.className = `notice notice-${kind}`
  notice.hidden = false
}

const uppy = new Uppy({
  autoProceed: false,
  restrictions: {
    maxFileSize: config.maxFileSize ?? null,
  },
})

uppy.use(Dashboard, {
  inline: true,
  target: '#uploader',
  width: '100%',
  height: 520,
  theme: 'auto',
  proudlyDisplayPoweredByUppy: false,
  showProgressDetails: true,
  hideProgressDetails: false,
  fileManagerSelectionType: 'files',
  note: 'Files are saved to the NAS upload folder. Existing files are never overwritten.',
  doneButtonHandler: () => {
    uppy.clear()
  },
})

uppy.use(Tus, {
  endpoint: config.endpoint,
  chunkSize: config.chunkSize,
  limit: config.parallelFiles,
  retryDelays: [0, 1000, 3000, 5000, 10000, 20000, 30000, 60000, 60000, 60000],
  headers: { 'X-CSRF-Token': config.csrfToken },
  removeFingerprintOnSuccess: true,
  allowedMetaFields: ['name', 'type'],
})

uppy.on('upload-error', (file, error, response) => {
  const status = response?.status ?? error?.originalResponse?.getStatus?.()
  if (status === 401) {
    showNotice('Your session has expired. Sign in again (reload this page), then add the same files to resume.')
  } else if (status === 403) {
    const body = error?.originalResponse?.getBody?.() ?? ''
    showNotice(body && !body.startsWith('{') ? body : 'The upload was refused. Reload the page and try again.')
  } else if (status === 507) {
    showNotice('The NAS is running out of space. Contact the administrator.')
  }
})

window.addEventListener('beforeunload', (event) => {
  const uploading = uppy.getFiles().some((f) => f.progress.uploadStarted && !f.progress.uploadComplete && !f.isPaused)
  if (uploading) {
    event.preventDefault()
  }
})
