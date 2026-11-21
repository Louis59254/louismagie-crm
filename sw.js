/* Service worker du CRM LouisMagie : notifications push uniquement.
   Volontairement AUCUN cache : le CRM doit toujours se charger dans sa dernière version.
   Le push arrive vide ; on va chercher le texte au serveur avec le SHA-256 de l'adresse
   d'abonnement (le texte ne transite pas par Apple ou Google). */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

async function sha256hex(t) {
  const b = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t));
  return [...new Uint8Array(b)].map((x) => x.toString(16).padStart(2, '0')).join('');
}

self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let msgs = [], dernier = null;
    try {
      const sub = await self.registration.pushManager.getSubscription();
      if (sub) {
        const r = await fetch('/api.php?action=pushBoite&ep=' + (await sha256hex(sub.endpoint)), { cache: 'no-store' });
        const j = await r.json();
        msgs = (j && j.msgs) || []; dernier = (j && j.dernier) || null;
      }
    } catch (_) {}
    // iOS exige une notification à chaque push : si la boîte est déjà vidée (deux pushs
    // rapprochés), on réaffiche le dernier message avec le même tag, qui remplace l'ancien.
    if (!msgs.length) msgs = [dernier || { titre: 'CRM LouisMagie', corps: 'Nouvelle notification', url: '/', tag: 'crm' }];
    await Promise.all(msgs.map((m) => self.registration.showNotification(m.titre || 'CRM LouisMagie', {
      body: m.corps || '', tag: m.tag || 'crm', icon: '/icon-192.png', badge: '/icon-192.png', data: { url: m.url || '/' },
    })));
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || '/', self.location.origin).href;
  e.waitUntil((async () => {
    const list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of list) {
      if (c.url.startsWith(self.location.origin) && 'focus' in c) {
        c.postMessage({ lien: url });   // la page ouvre la fiche sans se recharger
        return c.focus();
      }
    }
    return self.clients.openWindow(url);
  })());
});
