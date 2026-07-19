/* ============================================================================
   editor.js — interface d'édition du bloc « Formulaire Urbizen ».

   Aperçu STATIQUE : ni reprise du cadastre, ni sessionStorage, ni requête.
   Le formulaire réel n'existe que sur le site public, rendu par PHP.
   Écrit sans JSX ni étape de compilation.
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
	var SelectControl = components.SelectControl;
	var Notice = components.Notice;

	/* Seul type disponible à ce jour. La liste s'allongera quand un deuxième
	   parcours existera réellement. */
	var TYPES = [ { label: __( "Localisation du projet", "urbizen-platform" ), value: "localisation" } ];

	/* Même règle que côté PHP pour la clé de stockage. */
	var CLE_RE = /^[A-Za-z0-9_:-]+$/;

	var CHAMPS_APERCU = [
		__( "Adresse du terrain", "urbizen-platform" ),
		__( "Code postal", "urbizen-platform" ),
		__( "Commune", "urbizen-platform" ),
		__( "Section cadastrale", "urbizen-platform" ),
		__( "Numéro de parcelle", "urbizen-platform" ),
		__( "Surface cadastrale", "urbizen-platform" )
	];

	function apercu( attributes ) {
		return el(
			"div",
			{ className: "urbizen-form-preview" },
			el( "div", { className: "urbizen-form-preview-title" },
				__( "Localisation du projet", "urbizen-platform" ) ),
			el( "div", { className: "urbizen-form-preview-summary" },
				__( "La localisation confirmée sur la carte est reprise ici.", "urbizen-platform" ) ),
			el(
				"div",
				{ className: "urbizen-form-preview-fields" },
				CHAMPS_APERCU.map( function ( nom, i ) {
					return el( "div", { className: "urbizen-form-preview-field", key: i },
						el( "span", null, nom ) );
				} )
			),
			el( "div", { className: "urbizen-form-preview-note" },
				__( "Surface cadastrale indicative — modifiable par le visiteur.", "urbizen-platform" ) ),
			el( "div", { className: "urbizen-form-preview-actions" },
				el( "span", { className: "urbizen-form-preview-btn" },
					__( "Valider ma localisation", "urbizen-platform" ) ),
				el( "span", { className: "urbizen-form-preview-hint" },
					__( "Clé : ", "urbizen-platform" ) + ( attributes.storageKey || "parcel" ) ) )
		);
	}

	blocks.registerBlockType( "urbizen/formulaire", {

		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var cleInvalide = a.storageKey && ! CLE_RE.test( a.storageKey );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Formulaire", "urbizen-platform" ), initialOpen: true },
						el( SelectControl, {
							label: __( "Type de formulaire", "urbizen-platform" ),
							value: a.formType,
							options: TYPES,
							onChange: function ( v ) { set( { formType: v } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( "Liaison avec le cadastre", "urbizen-platform" ), initialOpen: true },
						el( TextControl, {
							label: __( "Clé de stockage", "urbizen-platform" ),
							help: __(
								"Doit être identique à celle du bloc cadastre dont ce formulaire reprend la localisation. Deux paires cadastre / formulaire sur une même page doivent utiliser deux clés distinctes.",
								"urbizen-platform"
							),
							value: a.storageKey,
							onChange: function ( v ) { set( { storageKey: v } ); }
						} ),
						cleInvalide
							? el( Notice, { status: "warning", isDismissible: false },
								__( "Clé ignorée : lettres, chiffres, tiret, tiret bas et deux-points uniquement.", "urbizen-platform" ) )
							: null,
						el( TextControl, {
							label: __( "Identifiant du formulaire", "urbizen-platform" ),
							help: __( "Facultatif. Utile pour distinguer plusieurs formulaires dans les événements.", "urbizen-platform" ),
							value: a.formId,
							onChange: function ( v ) { set( { formId: v } ); }
						} )
					)
				),
				el( "div", useBlockProps(), apercu( a ) )
			);
		},

		/* Rendu dynamique côté serveur : rien dans post_content. */
		save: function () { return null; }
	} );

} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n
);
