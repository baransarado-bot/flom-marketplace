/// <reference types="@capacitor-firebase/messaging" />

import type { CapacitorConfig } from '@capacitor/cli';

const publicUrl = process.env.FLOM_PUBLIC_URL?.trim().replace(/\/+$/, '');

if (publicUrl) {
  const parsedUrl = new URL(publicUrl);
  if (parsedUrl.protocol !== 'https:') {
    throw new Error('FLOM_PUBLIC_URL must use HTTPS.');
  }
}

const config: CapacitorConfig = {
  appId: 'id.go.florestimur.flommarketplace',
  appName: 'FLOM Marketplace',
  webDir: 'www',
  server: {
    cleartext: false,
    ...(publicUrl
      ? {
          url: publicUrl,
          allowNavigation: [new URL(publicUrl).hostname],
        }
      : {}),
  },
  plugins: {
    SplashScreen: {
      launchAutoHide: true,
      backgroundColor: '#123d33',
      showSpinner: false,
    },
    StatusBar: {
      backgroundColor: '#123d33',
      style: 'LIGHT',
    },
    FirebaseMessaging: {
      presentationOptions: ['alert', 'badge', 'sound'],
    },
  },
  experimental: {
    ios: {
      spm: {
        packageOptions: {
          '@capacitor-firebase/messaging': {
            symlink: process.platform !== 'win32',
          },
        },
      },
    },
  },
};

export default config;
