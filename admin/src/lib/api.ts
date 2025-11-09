import axios from 'axios'

const apiClient = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
})

// Add JWT token to requests
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Handle token expiration
apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      // Token expired or invalid - redirect to login
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export const api = {
  get: async (url: string) => {
    const response = await apiClient.get(url)
    return response.data
  },
  
  post: async (url: string, data?: any) => {
    const response = await apiClient.post(url, data)
    return response.data
  },
  
  put: async (url: string, data?: any) => {
    const response = await apiClient.put(url, data)
    return response.data
  },
  
  delete: async (url: string) => {
    const response = await apiClient.delete(url)
    return response.data
  },
}
