export function extractErrorMessage(errorOrErrors, fallback = 'Something went wrong. Please try again.') {
  if (!errorOrErrors) return fallback

  if (typeof errorOrErrors === 'string') return errorOrErrors

  // Inertia onError callback gives an object: { field: "msg" } or { field: ["msg"] }
  if (typeof errorOrErrors === 'object' && !errorOrErrors.response && !errorOrErrors.message) {
    const firstKey = Object.keys(errorOrErrors)[0]
    if (firstKey) {
      const value = errorOrErrors[firstKey]
      if (Array.isArray(value) && value[0]) return String(value[0])
      if (value) return String(value)
    }
  }

  // Axios-style error
  const response = errorOrErrors.response
  if (response) {
    const data = response.data
    if (typeof data === 'string' && data.trim()) return data
    if (data && typeof data === 'object') {
      if (typeof data.message === 'string' && data.message.trim()) return data.message
      if (data.errors && typeof data.errors === 'object') {
        const firstField = Object.keys(data.errors)[0]
        if (firstField) {
          const fieldErrors = data.errors[firstField]
          if (Array.isArray(fieldErrors) && fieldErrors[0]) return String(fieldErrors[0])
          if (fieldErrors) return String(fieldErrors)
        }
      }
    }
    if (response.status === 413) return 'File is too large.'
  }

  if (typeof errorOrErrors.message === 'string' && errorOrErrors.message.trim()) {
    return errorOrErrors.message
  }

  return fallback
}

