const routes = [];

export function onRoute(pattern, handler) {
  routes.push({ pattern, handler });
}

export function navigate(path) {
  window.location.hash = path;
}

function match(pattern, hash) {
  const patParts = pattern.split('/').filter(Boolean);
  const hashParts = hash.split('/').filter(Boolean);
  if (patParts.length !== hashParts.length) return null;
  const params = {};
  for (let i = 0; i < patParts.length; i++) {
    if (patParts[i].startsWith(':')) {
      params[patParts[i].slice(1)] = decodeURIComponent(hashParts[i]);
    } else if (patParts[i] !== hashParts[i]) {
      return null;
    }
  }
  return params;
}

function dispatch() {
  const hash = window.location.hash.replace('#', '') || '/personas';
  for (const route of routes) {
    const params = match(route.pattern, hash);
    if (params !== null) {
      route.handler(params);
      updateBottomNav(hash);
      return;
    }
  }
  navigate('/personas');
}

function updateBottomNav(hash) {
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.remove('active');
    const route = el.dataset.route;
    const active = hash === route ||
      (route === '/personas' && hash.startsWith('/editar/'));
    if (active) el.classList.add('active');
  });
}

export function initRouter() {
  window.addEventListener('hashchange', dispatch);
  dispatch();
}
