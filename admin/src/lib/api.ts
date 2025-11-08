import axios from 'axios'

const apiClient = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
  },
})

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
