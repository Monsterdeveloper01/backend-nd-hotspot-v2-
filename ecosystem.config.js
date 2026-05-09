module.exports = {
  apps: [
    {
      name: 'nd-radius-server',
      script: 'artisan',
      args: 'radius:serve',
      cwd: './',
      interpreter: 'php',
      instances: 1, // Tetap 1 karena RADIUS menggunakan UDP Port tunggal
      autorestart: true,
      watch: false,
      max_memory_restart: '4G', // Kita naikkan ke 4GB karena RAM Anda 32GB
      env: {
        NODE_ENV: 'production',
        PHP_CLI_MEMORY_LIMIT: '4096M' // Memastikan PHP CLI punya limit besar
      }
    }
  ]
};
