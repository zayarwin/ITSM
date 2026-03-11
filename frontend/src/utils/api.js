import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8001/api', // Laravel backend API
  timeout: 10000,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
});

export default api;
