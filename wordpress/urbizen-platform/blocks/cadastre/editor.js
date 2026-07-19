/* ============================================================================
   editor.js — interface d'édition du bloc « Cadastre Urbizen ».

   Portée volontairement limitée à l'éditeur :
     - réglages dans la barre latérale (InspectorControls) ;
     - aperçu STATIQUE du composant.

   Ce fichier n'appelle JAMAIS les services IGN et n'instancie JAMAIS Leaflet :
   l'éditeur ne doit ni consommer de quota, ni géolocaliser la rédactrice ou le
   rédacteur. Le composant réel n'existe que sur le site public, monté par
   urbizen-cadastre.js à partir du rendu dynamique produit en PHP.

   Écrit sans JSX ni étape de compilation : les scripts sont servis tels quels,
   comme le reste des assets de l'extension.
   ========================================================================== */
( function ( blocks, blockEditor, components, element, i18n ) {
	"use strict";

	var el = element.createElement;
	var __ = i18n.__;
	var Fragment = element.Fragment;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Notice = components.Notice;

	/* Même règle que côté PHP : une longueur CSS simple, rien d'autre.
	   Dupliquer la validation est délibéré — l'éditeur avertit tout de suite,
	   le serveur reste seul juge. */
	var MAP_HEIGHT_RE = /^\d{1,4}(px|vh|rem|em)$/;

	/* Aperçu statique : reproduit la structure du composant sans aucun
	   comportement. Les classes sont celles du CSS de production. */
	function preview( attributes ) {
		var height = MAP_HEIGHT_RE.test( attributes.mapHeight || "" )
			? attributes.mapHeight
			: "220px";

		return el(
			"div",
			{ className: "uc-root urbizen-cadastre-preview" },
			el(
				"div",
				{ className: "uc-field" },
				el( "label", { className: "uc-label" }, attributes.label || "" ),
				el( "div", { className: "uc-input urbizen-cadastre-preview-input" },
					attributes.placeholder || "" )
			),
			el(
				"div",
				{ className: "urbizen-cadastre-preview-map", style: { height: height } },
				el( "span", null, __( "Carte IGN — affichée sur le site public", "urbizen-platform" ) )
			),
			el(
				"div",
				{ className: "uc-continue-row" },
				el( "span", { className: "uc-continue urbizen-cadastre-preview-btn" },
					attributes.continueLabel || "" ),
				el( "span", { className: "uc-continue-hint" },
					__( "Confirmez votre parcelle pour continuer.", "urbizen-platform" ) )
			)
		);
	}

	blocks.registerBlockType( "urbizen/cadastre", {
		/* Attributs, titre, icône et catégorie viennent de block.json :
		   ils ne sont pas redéclarés ici. */

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var heightInvalid = a.mapHeight && ! MAP_HEIGHT_RE.test( a.mapHeight );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Libellés", "urbizen-platform" ), initialOpen: true },
						el( TextControl, {
							label: __( "Libellé du champ", "urbizen-platform" ),
							value: a.label,
							onChange: function ( v ) { set( { label: v } ); }
						} ),
						el( TextControl, {
							label: __( "Texte d’aide du champ", "urbizen-platform" ),
							value: a.placeholder,
							onChange: function ( v ) { set( { placeholder: v } ); }
						} ),
						el( TextControl, {
							label: __( "Texte du bouton Continuer", "urbizen-platform" ),
							value: a.continueLabel,
							onChange: function ( v ) { set( { continueLabel: v } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( "Réglages techniques", "urbizen-platform" ), initialOpen: false },
						el( TextControl, {
							label: __( "Clé de stockage", "urbizen-platform" ),
							help: __(
								"Identifie les données de localisation dans l’onglet du visiteur. Préfixée « urbizen: » automatiquement. Deux blocs qui partagent la même clé partagent la même localisation.",
								"urbizen-platform"
							),
							value: a.storageKey,
							onChange: function ( v ) { set( { storageKey: v } ); }
						} ),
						el( TextControl, {
							label: __( "Hauteur de la carte", "urbizen-platform" ),
							help: __( "Longueur CSS simple : 380px, 50vh, 24rem. Vide = hauteur par défaut.", "urbizen-platform" ),
							value: a.mapHeight,
							onChange: function ( v ) { set( { mapHeight: v } ); }
						} ),
						heightInvalid
							? el(
								Notice,
								{ status: "warning", isDismissible: false },
								__( "Hauteur ignorée : utilisez un format comme 380px, 50vh ou 24rem.", "urbizen-platform" )
							)
							: null
					)
				),
				el( "div", useBlockProps(), preview( a ) )
			);
		},

		/* Rendu dynamique : le serveur produit le HTML à chaque affichage.
		   Rien n'est écrit dans post_content, donc aucune adresse ni parcelle
		   n'est enregistrée dans la page. */
		save: function () {
			return null;
		}
	} );

} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n
);
