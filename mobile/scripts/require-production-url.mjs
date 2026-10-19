import { isIP } from 'node:net';

const value = process.env.FLOM_PUBLIC_URL?.trim();

if (!value) {
  console.error(
    'Set FLOM_PUBLIC_URL to the live HTTPS marketplace address before syncing a release, for example https://marketplace.example.go.id.',
  );
  process.exit(1);
}

let parsed;
try {
  parsed = new URL(value);
} catch {
  console.error('FLOM_PUBLIC_URL must be a valid absolute URL.');
  process.exit(1);
}

const hostname = parsed.hostname.replace(/^\[|\]$/g, '').toLowerCase();
const isLocalhost = hostname === 'localhost' || hostname.endsWith('.localhost');
const isLoopbackOrUnspecified = isIP(hostname) > 0
  && (hostname === '::1' || hostname === '::' || hostname === '0.0.0.0' || hostname.startsWith('127.'));

if (parsed.protocol !== 'https:' || parsed.username || parsed.password || isLocalhost || isLoopbackOrUnspecified) {
  console.error('FLOM_PUBLIC_URL must use HTTPS and be reachable outside the development computer.');
  process.exit(1);
}
