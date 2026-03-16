#!/bin/bash
# deploy.sh — Run this on your EC2 instance to deploy / update the app.
# Requirements on EC2: git, docker, docker compose plugin
#
# First-time setup:
#   chmod +x deploy.sh
#   ./deploy.sh
#
# To update after pushing new code:
#   git pull && ./deploy.sh

set -e

echo "==> Pulling latest code..."
git pull

echo "==> Building and starting containers..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

echo "==> Removing unused images..."
docker image prune -f

echo ""
echo "✓ Deployment complete. App is running on http://$(curl -s http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || hostname -I | awk '{print $1}')"
