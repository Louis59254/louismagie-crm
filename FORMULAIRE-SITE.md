# Brancher le formulaire de louismagie.fr sur le CRM

Objectif : quand un visiteur remplit le formulaire du site, la demande arrive **directement dans le CRM LouisMagie** (onglet Demandes) et Louis reçoit un email de notification.

---

## Côté CRM (déjà fait)

Un endpoint public existe : `POST https://louismagie.dunai.fr/api.php?action=newDemande`

Protections en place :
- **clé de formulaire** (générée dans Réglages du CRM) ;
- **origines autorisées** (`louismagie.fr` par défaut) ;
- **pot de miel** (champ invisible `website` — si rempli = robot) ;
- **limite de 5 demandes par heure et par IP** ;
- validation stricte (nom + email obligatoires, champs tronqués, caractères de contrôle retirés).

> La clé est visible dans le code de la page : ce n'est pas un secret sensible, juste un filtre anti-robot. Les vraies protections sont le pot de miel, la limite par IP et le fait que l'endpoint ne peut **que** créer une demande.

---

## Côté site (à faire dans le projet du site)

### 1. Récupérer le code
Dans le CRM : **Réglages → 🌐 Formulaire du site → Demandes** → bouton **« 📋 Copier le code pour le site »**.

Le code est un `<script>` autonome, à coller **avant `</body>`** sur les pages qui contiennent le formulaire (page contact + accueil).

### 2. Ce que le code attend du formulaire

Le formulaire actuel du site convient déjà : `<form class="contact-form" data-devis>` avec ces `name` :

| `name` du champ | Va dans le CRM |
|---|---|
| `name` | Nom du contact |
| `email` | Email |
| `phone` | Téléphone |
| `type` | Type d'événement |
| `date` | Date de l'événement |
| `lieu` | Lieu |
| `guests` | Nombre d'invités |
| `message` | Prestation souhaitée / notes |

Le script ajoute tout seul le champ pot de miel.

### 3. Comportement

- Le script intercepte l'envoi (`submit`), poste vers le CRM en `Content-Type: text/plain` (**volontaire** : évite le préflight CORS).
- Succès → le formulaire est masqué et le bloc `.form-success` s'affiche (déjà présent dans le site).
- Échec → message d'erreur avec repli sur `contact@louismagie.fr`.
- Déclenche `gtag('event','generate_lead')` si Google Analytics est présent.

### 4. Si le site est déployé sur un autre domaine

Ajouter le domaine dans le CRM : **Réglages → Domaines autorisés** (séparés par des virgules), par exemple pour une preview Netlify/Vercel.

---

## Vérification

1. Dans le CRM : **Réglages → 🧪 Envoyer une demande test** → une demande « Test formulaire » doit apparaître dans l'onglet **Demandes**.
2. Depuis le site en ligne : remplir le formulaire → vérifier l'arrivée dans Demandes + l'email de notification.

## En cas de problème

| Symptôme | Cause probable |
|---|---|
| `clé invalide` | Clé régénérée dans le CRM sans mettre à jour le code du site. |
| `formulaire non configuré` | La clé n'a pas encore été poussée au serveur → rouvrir Réglages, cliquer Enregistrer. |
| Erreur CORS dans la console | Domaine du site absent des « Domaines autorisés ». |
| `trop de demandes` | Limite de 5/heure/IP atteinte (normal en test répété). |
| Rien n'arrive | La nouvelle version d'`api.php` n'est pas déployée sur Coolify. |
