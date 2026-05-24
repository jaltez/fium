# Deployment Guide

## Production checklist

1. Build a release binary: `cargo build --release`
2. Set environment variables (`.env` or system env)
3. Disable debug mode: `FIUM_DEBUG=false`
4. Set a real API token secret (prefer 32+ random characters), either directly or via a mounted secret file:
   - `FIUM_API_TOKEN_SECRET=your-long-random-secret`
   - or `FIUM_API_TOKEN_SECRET_FILE=/run/secrets/fium_api_token_secret`
5. Configure TLS or use a reverse proxy
6. Set `FIUM_TRUSTED_PROXIES` if behind a load balancer

## Standalone deployment

Fium is a single binary. Copy it and your PHP files to the server:

```bash
scp target/release/fium server:/opt/myapp/
scp -r app.php public/ storage/ server:/opt/myapp/
```

### With TLS

```bash
fium serve app.php --tls-cert /etc/letsencrypt/live/example.com/fullchain.pem \
                   --tls-key /etc/letsencrypt/live/example.com/privkey.pem
```

Or via `fium.toml`:

```toml
[server]
host = "0.0.0.0"
port = 443

[tls]
cert = "/etc/letsencrypt/live/example.com/fullchain.pem"
key = "/etc/letsencrypt/live/example.com/privkey.pem"
```

## Behind Nginx

```nginx
upstream fium {
    server 127.0.0.1:3000;
}

server {
    listen 443 ssl;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        proxy_pass http://fium;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Set `FIUM_TRUSTED_PROXIES=127.0.0.1` so fium trusts Nginx's forwarded headers.

## systemd service

Create `/etc/systemd/system/fium.service`:

```ini
[Unit]
Description=Fium PHP Runtime
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/myapp
ExecStart=/opt/myapp/fium serve app.php
Restart=always
RestartSec=5
EnvironmentFile=/opt/myapp/.env

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable fium
sudo systemctl start fium
```

## Docker

```dockerfile
FROM rust:1.75 AS builder
WORKDIR /build
COPY . .
RUN cargo build --release

FROM php:8.3-cli
COPY --from=builder /build/target/release/fium /usr/local/bin/fium
WORKDIR /app
COPY app.php .
COPY public/ public/
COPY storage/ storage/
EXPOSE 3000
CMD ["fium", "serve", "--host", "0.0.0.0"]
```

```bash
docker build -t myapp .
docker run -p 3000:3000 -e FIUM_DEBUG=false myapp
```

## Monitoring

### Health check

```bash
curl http://localhost:3000/health
# ok

curl -H 'Accept: application/json' http://localhost:3000/health
# {"status":"ok","uptime_seconds":3600,"workers":4,"requests_total":1234,...}
```

### Prometheus metrics

```bash
curl http://localhost:3000/_fium/metrics
```

Exposed metrics:
- `fium_requests_total` — total requests handled
- `fium_worker_restarts_total` — worker restart count
- `fium_worker_errors_total` — worker error count
- `fium_workers_total` — number of worker processes
- `fium_uptime_seconds` — server uptime

### Production logging

```toml
[log]
format = "json"
level = "info"
```

JSON logs can be ingested by any log aggregation system (ELK, Loki, CloudWatch, etc.).
