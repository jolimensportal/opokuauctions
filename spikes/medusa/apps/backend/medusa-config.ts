import { loadEnv, defineConfig } from '@medusajs/framework/utils'

loadEnv(process.env.NODE_ENV || 'development', process.cwd())

module.exports = defineConfig({
  projectConfig: {
    databaseUrl: process.env.DATABASE_URL,
    http: {
      storeCors: process.env.STORE_CORS!,
      adminCors: process.env.ADMIN_CORS!,
      authCors: process.env.AUTH_CORS!,
      jwtSecret: process.env.JWT_SECRET,
      cookieSecret: process.env.COOKIE_SECRET,
    },
  },
  modules: [
    {
      resolve: '@medusajs/cache-redis',
      key: 'cache',
      options: {
        redisUrl: process.env.REDIS_URL,
      },
    },
    {
      resolve: '@medusajs/locking',
      options: {
        redisUrl: process.env.REDIS_URL,
        prefix: 'opoku-spike',
      },
    },
    {
      resolve: './src/modules/lots',
      key: 'lots',
    },
    {
      resolve: './src/modules/wallet',
      key: 'wallet',
    },
  ],
})
