import axios from 'axios';
import router from '../router';

const api = axios.create({
  baseURL: 'http://localhost:8000/api', // Laravel backend API is on 8000, middleware is on 8001
  timeout: 10000,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
});

// Request interceptor to add the auth token
api.interceptors.request.use(config => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor to handle 401 errors globally
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
      if (router.currentRoute.value.path !== '/login') {
         router.push('/login');
      }
    }
    return Promise.reject(error);
  }
);

export default api;
