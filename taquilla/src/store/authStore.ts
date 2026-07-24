import { createSignal } from 'solid-js';
import api from '../utils/api';

const [user, setUser] = createSignal(null);
const [token, setToken] = createSignal(localStorage.getItem('auth_token'));
const [isLoading, setIsLoading] = createSignal(false);

let listeners: Array<(state: any) => void> = [];

function notifyListeners() {
  const state = { user: user(), token: token(), isLoading: isLoading() };
  listeners.forEach(listener => listener(state));
}

export function subscribe(listener: (state: any) => void) {
  listeners.push(listener);
  return () => {
    listeners = listeners.filter(l => l !== listener);
  };
}

export const authStore = {
  get user() { return user(); },
  get token() { return token(); },
  get isLoading() { return isLoading(); },

  async login(email: string, password: string) {
    setIsLoading(true);
    try {
      const response = await api.post('/login', { email, password });
      const { token: newToken, user: userData } = response.data;

      localStorage.setItem('auth_token', newToken);
      setToken(newToken);
      setUser(userData);
      notifyListeners();

      return { success: true, data: userData };
    } catch (error: any) {
      const message = error.response?.data?.message || 'Error al iniciar sesión.';
      return { success: false, error: message };
    } finally {
      setIsLoading(false);
    }
  },

  async logout() {
    try {
      await api.post('/logout');
    } catch (error) {
      console.warn('Error en logout:', error);
    }
    localStorage.removeItem('auth_token');
    setToken(null);
    setUser(null);
    notifyListeners();
    window.location.href = '/login';
  },

  async checkAuth() {
    if (!token()) return false;
    try {
      const response = await api.get('/user');
      setUser(response.data);
      notifyListeners();
      return true;
    } catch (error) {
      localStorage.removeItem('auth_token');
      setToken(null);
      setUser(null);
      notifyListeners();
      return false;
    }
  },

  reset() {
    setToken(null);
    setUser(null);
    notifyListeners();
  }
};
