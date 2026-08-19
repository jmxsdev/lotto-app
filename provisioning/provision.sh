#!/usr/bin/env bash
# provision.sh — aprovisionamiento inicial del VPS (Debian 13)
# EJECUTADO el 2026-08-19 contra 166.1.88.100 (host-9d346c.ns.truo.co).
# Script de referencia/documentación; NO re-ejecutar sobre un VPS ya provisionado.
set -euo pipefail

# 1) Base del sistema
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq curl ca-certificates gnupg ufw fail2ban unattended-upgrades logrotate git rsync
apt-get -y -qq full-upgrade

# 2) Docker CE oficial
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian trixie stable" > /etc/apt/sources.list.d/docker.list
apt-get update -qq
apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# 3) Usuario deploy (sin sudo; grupo docker). Las llaves se agregan aparte.
useradd -m -s /bin/bash deploy || true
usermod -aG docker deploy

# 4) Servicios de seguridad
systemctl enable --now fail2ban
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# 5) Actualizaciones automáticas
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades

# 6) Swap 4G
fallocate -l 4G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
grep -q swapfile /etc/fstab || echo "/swapfile none swap sw 0 0" >> /etc/fstab
echo "vm.swappiness=10" > /etc/sysctl.d/99-swappiness.conf
sysctl -w vm.swappiness=10

# 7) Rotación de logs de Docker
cat > /etc/logrotate.d/docker-container << 'EOF'
/var/lib/docker/containers/*/*.log {
  daily
  rotate 7
  maxsize 10M
  compress
  delaycompress
  missingok
  copytruncate
}
EOF

# 8) Hardening SSH (ejecutar SOLO tras verificar el login por llave de deploy)
cat > /etc/ssh/sshd_config.d/99-hardening.conf << 'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
EOF
systemctl restart ssh

echo "Provisionamiento completado."
