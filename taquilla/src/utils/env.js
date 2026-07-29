const MAC_DEMO = '00:1A:2B:3C:4D:5E';

export async function getApiMac() {
  if (typeof window !== 'undefined' && window.electron?.getMac) {
    try {
      const mac = await window.electron.getMac();
      return mac && mac !== '00:00:00:00:00:00' ? mac : MAC_DEMO;
    } catch {
      return MAC_DEMO;
    }
  }
  return MAC_DEMO;
}
