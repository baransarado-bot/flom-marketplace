import { App } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { FirebaseMessaging } from './firebase-messaging.js';

const isNative = Capacitor.isNativePlatform();
let registeredPushToken = '';

function apiUrl(path) {
  return new URL(path, document.baseURI).toString();
}

function csrfToken() {
  return document.querySelector('meta[name="mp-csrf-token"]')?.content || '';
}

async function registerDeviceToken(token) {
  if (!token || token === registeredPushToken || !csrfToken()) {
    return;
  }

  const response = await fetch(apiUrl('api/mobile_push_device.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken(),
    },
    body: JSON.stringify({
      action: 'register',
      token,
      platform: Capacitor.getPlatform(),
    }),
  });
  if (!response.ok) {
    throw new Error(`Device registration failed with HTTP ${response.status}.`);
  }
  registeredPushToken = token;
}

function openInMarketplace(target) {
  if (typeof target !== 'string' || !target) {
    return;
  }
  const destination = new URL(target, document.baseURI);
  if (destination.origin !== window.location.origin) {
    return;
  }
  window.location.assign(destination.toString());
}

async function initializePushNotifications() {
  if (!isNative || !csrfToken() || !document.querySelector('meta[name="mp-user-role"]')) {
    return;
  }

  const permission = await FirebaseMessaging.checkPermissions();
  const result = permission.receive === 'granted'
    ? permission
    : await FirebaseMessaging.requestPermissions();
  if (result.receive !== 'granted') {
    return;
  }

  await FirebaseMessaging.addListener('tokenReceived', ({ token }) => {
    registerDeviceToken(token).catch((error) => {
      console.error('FLOM mobile notification registration failed.', error);
    });
  });
  await FirebaseMessaging.addListener('notificationActionPerformed', ({ notification }) => {
    openInMarketplace(notification.data?.url);
  });
  const { token } = await FirebaseMessaging.getToken();
  await registerDeviceToken(token);
}

function dataUrlToFile(dataUrl, fileName) {
  const [header, encoded] = dataUrl.split(',', 2);
  const mimeType = header.match(/^data:(.*?);base64$/)?.[1] || 'image/jpeg';
  const binary = atob(encoded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }
  return new File([bytes], fileName, { type: mimeType });
}

async function selectNativePhoto(input) {
  const photo = await Camera.getPhoto({
    quality: 88,
    allowEditing: false,
    resultType: CameraResultType.DataUrl,
    source: CameraSource.Prompt,
    promptLabelHeader: 'Pilih foto',
    promptLabelPhoto: 'Pilih dari galeri',
    promptLabelPicture: 'Ambil foto',
  });
  if (!photo.dataUrl) {
    return;
  }

  const transfer = new DataTransfer();
  transfer.items.add(dataUrlToFile(photo.dataUrl, `flom-${Date.now()}.jpg`));
  input.files = transfer.files;
  input.dispatchEvent(new Event('change', { bubbles: true }));
}

function enableNativePhotoPicker() {
  if (!isNative) {
    return;
  }

  document.addEventListener('click', (event) => {
    const input = event.target instanceof Element
      ? event.target.closest('input[type="file"][accept*="image"]')
      : null;
    if (!input || input.disabled) {
      return;
    }
    event.preventDefault();
    selectNativePhoto(input).catch((error) => {
      if (!error?.message?.toLowerCase().includes('cancel')) {
        console.error('FLOM could not open the photo picker.', error);
      }
    });
  });
}

async function initializeDeepLinks() {
  if (!isNative) {
    return;
  }

  await App.addListener('appUrlOpen', ({ url }) => {
    try {
      const incoming = new URL(url);
      if (incoming.protocol === 'flom:') {
        openInMarketplace(incoming.pathname.replace(/^\/+/, '') + incoming.search + incoming.hash);
      } else if (incoming.origin === window.location.origin) {
        openInMarketplace(incoming.pathname + incoming.search + incoming.hash);
      }
    } catch (error) {
      console.error('FLOM received an invalid application link.', error);
    }
  });
}

if (isNative) {
  enableNativePhotoPicker();
  initializePushNotifications().catch((error) => {
    console.error('FLOM native push initialization failed.', error);
  });
  initializeDeepLinks().catch((error) => {
    console.error('FLOM deep link initialization failed.', error);
  });
}

window.FLOMMobile = Object.freeze({
  isNative,
  open: openInMarketplace,
});
