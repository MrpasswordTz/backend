# Quick Fix for Network Error (HTTPS → HTTP Issue)

## The Problem
Your frontend on HTTPS (`https://mdukuzi-ai.vercel.app`) cannot make requests to your HTTP backend (`http://104.168.4.143:8000`) because browsers block mixed content (HTTPS → HTTP).

## Solution: Use Vercel Rewrites (Easiest Fix)

### Step 1: Create `vercel.json` in Your Frontend Project

Add this file to your **frontend project root** (not the backend):

```json
{
  "rewrites": [
    {
      "source": "/api/:path*",
      "destination": "http://104.168.4.143:8000/api/:path*"
    }
  ]
}
```

### Step 2: Update Your Frontend API Configuration

Change your API base URL from:
```javascript
// ❌ OLD - This causes mixed content error
const API_URL = 'http://104.168.4.143:8000/api';
```

To:
```javascript
// ✅ NEW - Use relative path, Vercel will proxy it
const API_URL = '/api';
// or
const API_URL = import.meta.env.VITE_API_URL || '/api';
```

### Step 3: Redeploy Your Frontend

1. Commit the `vercel.json` file
2. Push to your repository
3. Vercel will automatically redeploy

### Step 4: Test

After redeployment, your frontend at `https://mdukuzi-ai.vercel.app/api/login` will be proxied to `http://104.168.4.143:8000/api/login` through Vercel's servers, avoiding the mixed content issue.

---

## Alternative Solutions

### Option 2: Cloudflare Tunnel (Free HTTPS for Backend)

1. Install cloudflared on your server:
   ```bash
   # On Ubuntu/Debian
   wget https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
   chmod +x cloudflared-linux-amd64
   sudo mv cloudflared-linux-amd64 /usr/local/bin/cloudflared
   ```

2. Run the tunnel:
   ```bash
   cloudflared tunnel --url http://localhost:8000
   ```

3. You'll get an HTTPS URL like: `https://xxxxx.trycloudflare.com`

4. Update your frontend to use this HTTPS URL instead

### Option 3: Set Up SSL Certificate (Production Solution)

For a permanent solution, set up nginx with Let's Encrypt:

1. Install nginx and certbot
2. Configure nginx as reverse proxy
3. Get SSL certificate with Let's Encrypt
4. Update your backend to use HTTPS

---

## Verify It's Working

1. Open browser DevTools (F12)
2. Go to Network tab
3. Try logging in
4. Check the request:
   - Should go to `https://mdukuzi-ai.vercel.app/api/login` (not the IP)
   - Status should be 200 (not blocked)
   - Response should have CORS headers

## Still Having Issues?

1. Check Vercel deployment logs
2. Verify `vercel.json` is in the root of your frontend project
3. Make sure the backend is accessible: `curl http://104.168.4.143:8000/api/`
4. Check Laravel logs: `tail -f storage/logs/laravel.log`

