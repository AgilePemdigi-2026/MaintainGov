/** @type {import('next').NextConfig} */
const nextConfig = {
  // Single-domain deployment. Browser calls /api/v1; Next.js forwards
  // requests to the internal Laravel Apache service, keeping cookies same-origin.
  async rewrites() {
    const internalApi = process.env.INTERNAL_API_ORIGIN || 'http://api:80';
    return [
      { source: '/api/v1/:path*', destination: `${internalApi}/api/v1/:path*` },
    ];
  },
};

export default nextConfig;
