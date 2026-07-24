import axios from 'axios';

const isBrowser = typeof window !== 'undefined';

const api = axios.create({
  baseURL: import.meta.env.PUBLIC_API_URL || 'http://localhost:8000/api',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use(async (config) => {
  if (isBrowser) {
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    if (window.electron?.getMac) {
      try {
        const mac = await window.electron.getMac();
        if (mac && mac !== '00:00:00:00:00:00') {
          config.headers['X-Device-MAC'] = mac;
        }
      } catch (error) {
        console.warn('No se pudo obtener MAC:', error);
      }
    }
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (!isBrowser) return Promise.reject(error);
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }
    if (error.response?.status === 403) {
      const message = error.response.data?.message || 'Acceso denegado.';
      alert(message);
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default api;
