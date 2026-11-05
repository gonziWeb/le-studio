# Le Studio

Une petite page pour s'envoyer des bouts de musique à deux : enregistrer ou importer un morceau, l'écouter, le commenter (y compris à un moment précis), et ranger les morceaux par projet.

PHP 7.4+ et c'est tout : pas de base de données, pas de dépendance.

## Mise en ligne chez OVH (Git + Multisite)

1. **Espace client OVH** → Web Cloud → Hébergements → ton hébergement → onglet **Multisite**.
2. Ajoute (ou choisis) le domaine / sous-domaine, avec un **dossier vide** (ex. `le-studio`). Active le **SSL** (case « SSL » / Let's Encrypt) : le micro ne fonctionne qu'en https.
3. Sur la ligne du domaine : `…` → **Associer Git**.
   - Dépôt : `git@github.com:gonziWeb/le-studio.git` (format SSH, le dépôt est privé)
   - Branche : `main`
   - Copie la **clé SSH** affichée par OVH → GitHub → *Settings* → *SSH and GPG keys* → *New SSH key*.
   - Copie l'**URL de webhook** → dépôt GitHub → *Settings* → *Webhooks* → *Add webhook* (Content type : `application/json`). Chaque `git push` mettra le site à jour.
4. Ouvre le site **tout de suite** : la première personne qui l'ouvre crée le compte **administrateur**. Ensuite, l'admin crée les autres comptes (menu avec ton prénom, en haut à droite > « Gérer les comptes »).
5. Le site redirige automatiquement vers `https://` (règle dans `.htaccess`).

## Où sont les données ?

Les morceaux et les commentaires sont rangés **à côté** du dossier du site, dans `le-studio-data/` (pas dans le dépôt Git, donc un déploiement ne les efface pas, et ils ne sont pas accessibles directement depuis le web).
Si ce dossier ne peut pas être créé, l'appli se rabat sur `data/` dans le dossier du site (protégé par `.htaccess`).

Pour faire une sauvegarde : récupère `le-studio-data/` par FTP.

## Comptes

- L'administrateur ajoute, supprime des comptes et change leurs mots de passe.
- Chacun peut changer son propre mot de passe (menu avec son prénom).
- Chacun peut supprimer ses morceaux et commentaires ; l'admin peut tout supprimer.
- Mot de passe admin perdu : supprime `le-studio-data/users.json` par FTP puis ouvre le site pour recréer le compte admin (les morceaux sont conservés, les comptes sont à recréer).

## Taille des fichiers

La taille max d'envoi dépend de la configuration PHP de l'hébergement. Le fichier `.user.ini` demande 200 Mo ; la limite réellement appliquée est affichée dans la fenêtre « Nouveau morceau ».

## Fichiers

- `index.html` — toute l'interface
- `api.php` — l'API (mot de passe, morceaux, commentaires, lecture audio)
- `.htaccess`, `data/.htaccess`, `.user.ini` — réglages serveur
