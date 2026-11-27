// Native form POST so the browser handles a file download (Inertia would treat the binary
// response as an error). Arrays are sent as name[] fields.
export function postDownload(action, fields) {
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = action
  form.style.display = 'none'
  const append = (name, value) => {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = value ?? ''
    form.appendChild(input)
  }
  append('_token', document.querySelector('meta[name=csrf-token]')?.content ?? '')
  for (const [name, value] of Object.entries(fields)) {
    if (Array.isArray(value)) value.forEach(v => append(`${name}[]`, v))
    else append(name, value)
  }
  document.body.appendChild(form)
  form.submit()
  form.remove()
}
