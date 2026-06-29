#!/bin/bash
# ==============================================================================
# Script de configuration de Selenium & Chrome pour Quora Importer
# ==============================================================================
# Ce script installe les dépendances requises (Python 3, Chrome, Selenium)
# et configure les permissions du dossier utilisateur pour le serveur web.
# ==============================================================================

# Couleurs pour le terminal
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # Pas de couleur

echo -e "${BLUE}=== Configuration du serveur pour Quora Importer ===${NC}\n"

# 1. Vérification des droits root
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}Erreur : Ce script doit être exécuté en tant que root (sudo).${NC}"
  exit 1
fi

# 2. Détection du gestionnaire de paquets
if [ -f /usr/bin/apt-get ]; then
  echo -e "${BLUE}[1/4] Installation des paquets système (Debian/Ubuntu)...${NC}"
  apt-get update
  apt-get install -y python3 python3-pip python3-venv wget curl unzip gnupg
  
  # Installation de Google Chrome Stable
  if ! command -v google-chrome &> /dev/null; then
    echo -e "${BLUE}Téléchargement et installation de Google Chrome...${NC}"
    wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add -
    sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google-chrome.list'
    apt-get update
    apt-get install -y google-chrome-stable
  fi
else
  echo -e "${RED}Attention: Gestionnaire de paquets apt non détecté.${NC}"
  echo "Veuillez vous assurer que Python 3, pip, et Google Chrome sont installés manuellement."
fi

# 3. Installation des librairies Python requises
echo -e "${BLUE}[2/4] Installation des dépendances Python (Selenium)...${NC}"
# Utilise option --break-system-packages si disponible pour les distributions récentes (Debian 12+)
if python3 -m pip install --help | grep -q "break-system-packages"; then
  python3 -m pip install --break-system-packages selenium
else
  python3 -m pip install selenium
fi

# 4. Détermination de l'utilisateur du serveur web
echo -e "${BLUE}[3/4] Configuration des répertoires pour le serveur web...${NC}"
WEB_USER=""
if id "www-data" &>/dev/null; then
  WEB_USER="www-data"
elif id "nginx" &>/dev/null; then
  WEB_USER="nginx"
elif id "apache" &>/dev/null; then
  WEB_USER="apache"
else
  # Recherche de l'utilisateur exécutant php-fpm ou apache/nginx
  WEB_USER=$(ps aux | grep -E 'apache|nginx|php-fpm' | grep -v root | head -n 1 | cut -d' ' -f1)
  if [ -empty "$WEB_USER" ]; then
    WEB_USER="www-data" # Fallback par défaut
  fi
fi

echo -e "Utilisateur serveur web identifié : ${GREEN}${WEB_USER}${NC}"

# Récupération du répertoire HOME de l'utilisateur du serveur web
WEB_USER_HOME=$(eval echo "~$WEB_USER")
if [ ! -d "$WEB_USER_HOME" ]; then
  echo -e "${RED}Attention: Le répertoire home ($WEB_USER_HOME) n'existe pas. Création...${NC}"
  mkdir -p "$WEB_USER_HOME"
fi

# Création des dossiers de cache et de configuration indispensables pour Chrome
echo "Configuration de l'accès en écriture pour Chrome..."
mkdir -p "$WEB_USER_HOME/.config"
mkdir -p "$WEB_USER_HOME/.cache"
mkdir -p "$WEB_USER_HOME/.local"

# Assurer que l'utilisateur web possède ces répertoires pour éviter les plantages de profil Chrome
chown -R "$WEB_USER":"$WEB_USER" "$WEB_USER_HOME/.config"
chown -R "$WEB_USER":"$WEB_USER" "$WEB_USER_HOME/.cache"
chown -R "$WEB_USER":"$WEB_USER" "$WEB_USER_HOME/.local"

# 5. Création et droits pour le profil Chrome persistant de Quora Importer
# Par défaut, le script python essaie de créer le dossier ~/.config/quora_importer_chrome_profile
# Pour l'utilisateur web, il s'agira de $WEB_USER_HOME/.config/quora_importer_chrome_profile
CH_PROFILE_DIR="$WEB_USER_HOME/.config/quora_importer_chrome_profile"
mkdir -p "$CH_PROFILE_DIR"
chown -R "$WEB_USER":"$WEB_USER" "$CH_PROFILE_DIR"
chmod -R 755 "$CH_PROFILE_DIR"

echo -e "${GREEN}Permissions configurées avec succès pour $CH_PROFILE_DIR !${NC}"

# 6. Test final de validation
echo -e "${BLUE}[4/4] Validation de l'environnement...${NC}"
echo -n "Version de Python : "
python3 --version
echo -n "Version de Chrome : "
google-chrome --version 2>/dev/null || chromium-browser --version 2>/dev/null || echo -e "${RED}Non trouvé${NC}"

echo -e "\n${GREEN}=== Configuration terminée avec succès ! ===${NC}"
echo -e "Les commentaires Quora peuvent maintenant être importés de manière différée (Deferred/cron) sans heurts."
