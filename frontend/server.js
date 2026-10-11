const express = require('express');
const cors    = require('cors');
const morgan  = require('morgan');
const path    = require('path');
const { createProxyMiddleware } = require('http-proxy-middleware');
require('dotenv').config();

const app  = express();
const PORT = process.env.PORT || 3000;
const API  = process.env.BACKEND_URL || 'http://localhost:5000';

app.use(cors());
app.use(morgan('dev'));

// Proxy all /api/* requests to the Python backend
app.use('/api', createProxyMiddleware({
  target: `${API}/api`,
  changeOrigin: true,
  on: {
    error: (err, req, res) => {
      console.error('[Proxy Error]', err.message);
      res.status(502).json({ error: 'Backend unavailable', detail: err.message });
    }
  }
}));

// Serve static frontend
app.use(express.static(path.join(__dirname, 'public')));

// SPA fallback
app.get('*', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

app.listen(PORT, () => {
  console.log(`\n  🏆  CodeArena running at http://localhost:${PORT}`);
  console.log(`  🐍  Python backend: ${API}`);
  console.log(`  📁  Serving static from: ${path.join(__dirname, 'public')}\n`);
});
