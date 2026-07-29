/**
 * Urbizen — protection dissuasive des visuels de la page Conception.
 *
 * Portée : uniquement les conteneurs marqués `[data-urbizen-protected-media]`
 * (hero et galerie). Le reste de la page et du site n'est jamais touché : le
 * menu contextuel, la sélection et le glisser-déposer restent normaux ailleurs.
 *
 * Rôle : compliquer la récupération simple des images (glisser vers le bureau,
 * « enregistrer l'image sous… », sélection). Ces mesures ne rendent PAS la
 * capture d'écran impossible et n'ont aucune valeur de sécurité : elles ne font
 * que dissuader la réutilisation triviale. Aucune incidence sur l'accessibilité
 * (navigation clavier, lecteurs d'écran et zoom restent intacts).
 */
(function () {
  'use strict';

  function within(target) {
    return target && typeof target.closest === 'function'
      ? target.closest('[data-urbizen-protected-media]')
      : null;
  }

  function block(event) {
    if (within(event.target)) {
      event.preventDefault();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var zones = document.querySelectorAll('[data-urbizen-protected-media]');

    if (!zones.length) {
      return;
    }

    // Attributs défensifs sur chaque image concernée.
    zones.forEach(function (zone) {
      zone.querySelectorAll('img').forEach(function (img) {
        img.setAttribute('draggable', 'false');
      });
    });

    // Écouteurs délégués, limités aux zones protégées.
    document.addEventListener('contextmenu', block);
    document.addEventListener('dragstart', block);
  });
})();
