const BASE_URL = '../api';

export async function fetchMunicipios() {
  const res = await fetch(`${BASE_URL}/municipios/index.php`);
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);
  return res.json();
}

export async function syncData(payload) {
  const res = await fetch(`${BASE_URL}/personas/sync.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  if (!res.ok) throw new Error(`Error HTTP ${res.status}`);
  const data = await res.json();
  if (!data.success) throw new Error(data.message || 'Error en sincronización');
  return data;
}
