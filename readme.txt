=== SEO Automatique ===
Contributors: loris383
Tags: seo,automatisation
Tested up to: 6.9
Stable tag: latest
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Générer automatiquement les données SEO de vos posts et pages.

== Description ==
Cette extension permet de générer automatiquement les méta-descriptions et les expressions clés de tous vos articles et pages!
Elle prend le titre de l'article en tant qu'expression clé, et les 15 premiers mots de l'article pour la méta-description (pas encore configurable).

Il est possible de filtrer pour modifier uniquement les articles appartenant à certaines catégories.

Les champs qui étaient déjà remplis ne seront par défaut pas modifiés, pour éviter d'écraser un travail manuel de meilleure qualité.
== Changelog ==
= 2.1.2 =
* [**Correctif**] : Suppression des antislash qui apparaissaient de plus en plus devant les apostrophes à chaque enregistrement du contexte.

= 2.1.1 =
* [**Amélioration**] : Ajout d'un champ de contexte du site pour que l'IA fasse des descriptions plus adaptées.

= 2.1.0 =
* [**Nouveau/Amélioration**] : Traitement en arrière plan, permet notamment d'utiliser l'IA en masse, ce qui était impossible sur des gros sites avant (à moins de rester avec le navigateur ouvert pendant une semaine...).

= 2.0.1 =
* [**Amélioration**] : Meilleure gestion des limites de taux. IA en masse à éviter sur des gros sites en attendant une prochaine mise à jour, car il faut encore rester sur la page.

= 2.0.0 =
* [**Nouveau**] : Support génération gratuite par IA des balises. Support encore très limité pour l'optimisation de masse à cause des limites de taux, sera corrigé dans une prochaine mise-à-jour

= 1.2.1 =
* [**Correctif**] : Erreur dans la page des réglages avec les valeurs quand elles n'étaient pas encore définies

= 1.2.0 =
* [**Nouveau**] : Longueur des champs configurables

= 1.1.1 =
* [**Correctif**] : Plugin impossible à activer
* [**Correctif**] : Mise à jour renvoyant sur un mauvais plugin

= 1.1.0 =
* [**Nouveau**] : Ajout de la possibilité de mettre à jour directement depuis WordPress
* [**Amélioration**] : Readme.txt un peu plus adapté pour WordPress

= 1.0.3 =
* [**Amélioration**] : Catégorie principale ajoutée dans la méta-description

= 1.0.2 =
* [**Amélioration**] : Réglages par défaut changés. (Automatisation lors de la publication -> Oui par défaut, pour les articles et les pages.)

= 1.0.1 =
* [**Correctif**] : Les champs ne se remplissaient pas automatiquement lors de la publication/mise à jour d'un article ou d'une page.
* [**Amélioration**] : Logs après optimisation un peu plus verbeux.

= 1.0 =
* [**Nouveau**] : Remplissage automatique lors de la publication d'un nouvel article ou d'une page.
* [**Nouveau**] : Page de réglages pour activer/désactiver l'automatisation à la volée.
* [**Nouveau**] : Choix des types de contenu (articles/pages) pour l'automatisation.
* [**Amélioration**] : Restructuration du menu avec deux sous menus.
* [**Amélioration**] : Ajout d'un lien direct vers les réglages depuis la liste des extensions.

= 0.4 =
* [**Nouveau**] : Prise en charge des pages, choix sur le type de contenu à traiter.
* [**Nouveau**] : Informations sur les contenus modifiés/ignorés après exécution.
* [**Amélioration**] : Déplacement dans un menu principal au lieu d'un sous-menu d'Articles, maintenant que l'extension gère aussi les pages.

= 0.3 =
* [**Nouveau**] : Activer/désactiver la protection des champs déjà remplis.
* [**Nouveau**] : Liste noire des catégories en complément pour un filtrage plus précis.
* [**Amélioration**] : La dépendance pour Yoast est mieux gérée.

= 0.2 =
* [**Nouveau**] : Filtre par catégories.
* [**Nouveau**] : Empêcher l'extension de modifier les champs déjà remplis.
* [**Nouveau**] : Remplis l'expression clé.

= 0.1 =
* **Plugin fonctionnel**, écrit les méta-descriptions