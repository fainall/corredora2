// ============================================================
// GPRB - Cliente MySQL/PHP (reemplazo de supabase-client)
// Expone window.GPRB_SB con la misma interfaz exacta
// ============================================================

const API_BASE = '/api.php';
const AUTH_STORAGE_KEY = 'gprb_mysql_auth';

// ── Internal state ──────────────────────────────────────────
let _authUser = null;
let _authCallbacks = [];

// ── HTTP helpers ────────────────────────────────────────────
async function apiGet(action) {
  const res = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, {
    credentials: 'include'
  });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Error del servidor');
  return json.data;
}

async function apiPost(action, body) {
  const res = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Error del servidor');
  return json.data;
}

async function apiPostFile(action, file) {
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    credentials: 'include',
    body: form
  });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'Error del servidor');
  return json.data;
}

// ── Auth helpers ────────────────────────────────────────────
function _saveAuth(user) {
  _authUser = user;
  if (user) {
    localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(user));
  } else {
    localStorage.removeItem(AUTH_STORAGE_KEY);
  }
}

function _loadAuth() {
  try {
    const stored = localStorage.getItem(AUTH_STORAGE_KEY);
    if (stored) {
      _authUser = JSON.parse(stored);
    }
  } catch (e) {
    _authUser = null;
  }
}

function _notifyAuthChange(user) {
  _authCallbacks.forEach(cb => {
    try { cb(user); } catch (e) { console.warn('onAuthChange callback error', e); }
  });
}

// ── AUTH ─────────────────────────────────────────────────────
async function sbSignIn(email, password) {
  // The frontend sends email (user@gprb.cl) or just username.
  // The API handles stripping @gprb.cl
  const data = await apiPost('login', { email, password });
  const user = data?.user || data;
  _saveAuth(user);
  _notifyAuthChange(user);
  return { user };
}

async function sbSignOut() {
  try {
    await apiPost('logout', {});
  } catch (e) {
    console.warn('logout api error', e);
  }
  _saveAuth(null);
  _notifyAuthChange(null);
}

async function sbGetSession() {
  try {
    const data = await apiGet('session');
    if (data && data.user) {
      _saveAuth(data.user);
      return { user: data.user };
    }
  } catch (e) {
    console.warn('getSession error', e);
  }
  // If server session expired, clear local too
  _saveAuth(null);
  return null;
}

function sbOnAuthChange(cb) {
  _authCallbacks.push(cb);
  return { unsubscribe: () => { _authCallbacks = _authCallbacks.filter(c => c !== cb); } };
}

// ── PROPERTIES ──────────────────────────────────────────────
async function sbGetProperties() {
  try {
    const data = await apiGet('properties');
    return (data || []).map(propFromApi);
  } catch (e) {
    console.error('sbGetProperties', e);
    return [];
  }
}

async function sbCreateProperty(prop) {
  const payload = propToApi(prop);
  const data = await apiPost('save_property', payload);
  return propFromApi(data);
}

async function sbUpdateProperty(id, prop) {
  const payload = propToApi(prop);
  payload.id = id;
  const data = await apiPost('save_property', payload);
  return propFromApi(data);
}

async function sbDeleteProperty(id) {
  await apiPost('delete_property', { id });
  return true;
}

// ── MAPPERS ─────────────────────────────────────────────────
// The API already returns camelCase (prop_from_db in api.php).
// These mappers ensure consistency with the app's expected format.

function propFromApi(r) {
  if (!r) return null;
  return {
    id: r.id,
    title: r.title || '',
    type: r.type || '',
    status: r.status || '',
    price: Number(r.price) || 0,
    priceUnit: r.priceUnit || 'UF',
    priceWithIva: r.priceWithIva !== false,
    gastosComunes: r.gastosComunes != null ? Number(r.gastosComunes) : null,
    gastosComunesUnit: r.gastosComunesUnit || 'UF',
    gastosComunesWithIva: r.gastosComunesWithIva === true,
    location: r.location || '',
    address: r.address || '',
    area: r.area != null ? Number(r.area) : null,
    areaBodega: r.areaBodega != null ? Number(r.areaBodega) : null,
    areaOficina: r.areaOficina != null ? Number(r.areaOficina) : null,
    areaAltillo: r.areaAltillo != null ? Number(r.areaAltillo) : null,
    areaPatio: r.areaPatio != null ? Number(r.areaPatio) : null,
    patioSumaTotal: r.patioSumaTotal === true,
    usableArea: r.usableArea != null ? Number(r.usableArea) : null,
    bathrooms: r.bathrooms || 0,
    parking: r.parking || 0,
    portones: r.portones != null ? Number(r.portones) : null,
    andenes: r.andenes != null ? Number(r.andenes) : null,
    cumbrera: r.cumbrera != null ? Number(r.cumbrera) : null,
    privateRooms: r.privateRooms || 0,
    age: r.age,
    height: r.height != null ? Number(r.height) : null,
    floorSupport: r.floorSupport != null ? Number(r.floorSupport) : null,
    platforms: r.platforms,
    pricePerM2: r.pricePerM2 != null ? Number(r.pricePerM2) : null,
    propertyCode: r.propertyCode || '',
    portalCode: r.portalCode || null,
    frente: r.frente != null ? Number(r.frente) : null,
    fondo: r.fondo != null ? Number(r.fondo) : null,
    formaTerreno: r.formaTerreno || null,
    image: r.image || '',
    gallery: Array.isArray(r.gallery) ? r.gallery : [],
    description: r.description || '',
    videoUrl: r.videoUrl || null,
    services: Array.isArray(r.services) ? r.services : [],
    amenities: Array.isArray(r.amenities) ? r.amenities : [],
    security: Array.isArray(r.security) ? r.security : [],
    featured: !!r.featured,
    published: r.published !== false
  };
}

function propToApi(p) {
  // Send the camelCase data as-is; api.php maps it to DB columns
  const out = {
    title: p.title,
    type: p.type,
    status: p.status,
    price: p.price,
    priceUnit: p.priceUnit || 'UF',
    priceWithIva: p.priceWithIva !== false,
    gastosComunes: p.gastosComunes || null,
    gastosComunesUnit: p.gastosComunesUnit || 'UF',
    gastosComunesWithIva: p.gastosComunesWithIva === true,
    location: p.location,
    address: p.address,
    area: p.area,
    areaBodega: p.areaBodega || null,
    areaOficina: p.areaOficina || null,
    areaAltillo: p.areaAltillo || null,
    areaPatio: p.areaPatio || null,
    patioSumaTotal: p.patioSumaTotal === true,
    usableArea: p.usableArea,
    bathrooms: p.bathrooms,
    parking: p.parking,
    portones: p.portones || null,
    andenes: p.andenes || null,
    cumbrera: p.cumbrera || null,
    privateRooms: p.privateRooms,
    age: p.age,
    height: p.height,
    floorSupport: p.floorSupport,
    platforms: p.platforms,
    pricePerM2: p.pricePerM2,
    propertyCode: p.propertyCode,
    portalCode: p.portalCode || null,
    frente: p.frente || null,
    fondo: p.fondo || null,
    formaTerreno: p.formaTerreno || null,
    image: p.image,
    gallery: p.gallery || [],
    description: p.description,
    videoUrl: p.videoUrl || null,
    services: p.services || [],
    amenities: p.amenities || [],
    security: p.security || [],
    featured: !!p.featured
  };
  // Remove undefined keys to avoid issues
  Object.keys(out).forEach(k => out[k] === undefined && delete out[k]);
  return out;
}

// ── SLIDES ──────────────────────────────────────────────────
async function sbGetSlides() {
  try {
    const data = await apiGet('slides');
    return (data || []).map(slideFromApi);
  } catch (e) {
    console.error('sbGetSlides', e);
    return [];
  }
}

async function sbSaveSlides(slides) {
  const payload = (slides || []).map((s, i) => ({
    bgUrl: s.bgUrl,
    tag: s.tag,
    title: s.title,
    subtitle: s.subtitle,
    sortOrder: s.sortOrder != null ? s.sortOrder : (i + 1)
  }));
  const data = await apiPost('save_slides', { slides: payload });
  return (data || []).map(slideFromApi);
}

function slideFromApi(r) {
  if (!r) return null;
  return {
    id: r.id,
    bgUrl: r.bgUrl || '',
    tag: r.tag || '',
    title: r.title || '',
    subtitle: r.subtitle || '',
    sortOrder: r.sortOrder || 0
  };
}

// ── CONTACT ─────────────────────────────────────────────────
async function sbSendContactMessage(msg) {
  const data = await apiPost('save_contact', msg);
  return data;
}

async function sbGetContactMessages() {
  try {
    const data = await apiGet('contacts');
    return data || [];
  } catch (e) {
    console.error('sbGetContactMessages', e);
    return [];
  }
}

// ── IMAGES ──────────────────────────────────────────────────
async function sbUploadImage(file) {
  if (!file) throw new Error('Archivo vacio');
  const data = await apiPostFile('upload_image', file);
  return data.url;
}

async function sbUploadImages(files) {
  const list = Array.from(files || []);
  const urls = [];
  for (const f of list) {
    try {
      urls.push(await sbUploadImage(f));
    } catch (e) {
      console.error('sbUploadImages fallo', f.name, e);
    }
  }
  return urls;
}

async function sbDeleteImageByUrl(url) {
  try {
    await apiPost('delete_image', { url });
    return true;
  } catch (e) {
    console.warn('sbDeleteImageByUrl', e);
    return false;
  }
}

// ── CATEGORIES ──────────────────────────────────────────────
async function sbGetCategories() {
  try {
    const data = await apiGet('categories');
    return Array.isArray(data) ? data : [];
  } catch (e) {
    console.error('sbGetCategories', e);
    return [];
  }
}

async function sbCreateCategory(name) {
  return await apiPost('save_category', { name });
}

async function sbDeleteCategory(id) {
  return await apiPost('delete_category', { id });
}

// ── INIT: restore auth from localStorage on load ────────────
_loadAuth();

// Validate the stored session against the server (async, non-blocking)
if (_authUser) {
  sbGetSession().catch(() => {});
}

// ── EXPOSE GLOBAL INTERFACE ─────────────────────────────────
window.GPRB_SB = {
  getProperties:      sbGetProperties,
  createProperty:     sbCreateProperty,
  updateProperty:     sbUpdateProperty,
  deleteProperty:     sbDeleteProperty,
  getSlides:          sbGetSlides,
  saveSlides:         sbSaveSlides,
  sendContactMessage: sbSendContactMessage,
  getContactMessages: sbGetContactMessages,
  signIn:             sbSignIn,
  signOut:            sbSignOut,
  getSession:         sbGetSession,
  onAuthChange:       sbOnAuthChange,
  uploadImage:        sbUploadImage,
  uploadImages:       sbUploadImages,
  deleteImageByUrl:   sbDeleteImageByUrl,
  getCategories:      sbGetCategories,
  createCategory:     sbCreateCategory,
  deleteCategory:     sbDeleteCategory,
};
