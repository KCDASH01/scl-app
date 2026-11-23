// Set server-side timezone before Next.js boots
process.env.TZ = 'Asia/Kolkata';

// Production builds are a static export so the app can be served by Apache on shared hosting.
const isBuild = process.env.NODE_ENV === 'production';

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  // Keep production builds from overwriting a running development server's assets.
  distDir: isBuild ? '.next' : '.next-dev',
  ...(isBuild && {
    output: 'export',
    trailingSlash: true,
    images: { unoptimized: true },
  }),
};

export default nextConfig;
