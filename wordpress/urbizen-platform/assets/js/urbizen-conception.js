/**
 * Parcours de conception : navigation, conditions, validation, estimation.
 *
 * Trois règles tiennent ce fichier.
 *
 * Le schéma vient du serveur — il n'y a **pas** de seconde définition du
 * formulaire ici, ni de seconde table tarifaire. Ce script lit ce que
 * `ConceptionSchema` lui donne.
 *
 * L'estimation affichée est une commodité. Le prix qui engage est celui que le
 * serveur recalcule ; toute valeur envoyée par le navigateur est ignorée.
 *
 * Aucune valeur du formulaire n'atterrit dans la console.
 */
( function () {
	'use strict';

	var RACINE = 'urbizen-conception';

	/**
	 * Trouve les champs d'une étape, conditions comprises.
	 */
	function champsDe( etape ) {
		return Array.prototype.slice.call( etape.querySelectorAll( '[data-field]' ) );
	}

	/**
	 * Valeurs actuelles d'un champ, quel que soit son type.
	 */
	function valeursDe( formulaire, nom ) {
		var controles = formulaire.querySelectorAll(
			'[name="' + nom + '"], [name="' + nom + '[]"]'
		);
		var valeurs = [];

		Array.prototype.forEach.call( controles, function ( c ) {
			if ( c.type === 'checkbox' || c.type === 'radio' ) {
				if ( c.checked ) {
					valeurs.push( c.value );
				}
			} else if ( c.type !== 'file' && c.value !== '' ) {
				valeurs.push( c.value );
			}
		} );

		return valeurs;
	}

	/**
	 * Un champ conditionnel est-il pertinent ?
	 */
	function estPertinent( formulaire, bloc ) {
		var pilote = bloc.getAttribute( 'data-visible-if' );

		if ( ! pilote ) {
			return true;
		}

		var attendues = ( bloc.getAttribute( 'data-visible-in' ) || '' ).split( '|' );
		var actuelles = valeursDe( formulaire, pilote );

		return actuelles.some( function ( v ) {
			return attendues.indexOf( v ) !== -1;
		} );
	}

	function Parcours( racine, schema ) {
		this.racine = racine;
		this.schema = schema;
		this.form = racine.querySelector( '.' + RACINE + '__form' );
		this.etapes = Array.prototype.slice.call( racine.querySelectorAll( '.' + RACINE + '__etape' ) );
		this.progression = Array.prototype.slice.call(
			racine.querySelectorAll( '.' + RACINE + '__progression-item' )
		);
		this.resume = racine.querySelector( '.' + RACINE + '__erreurs' );
		this.annonce = racine.querySelector( '.' + RACINE + '__annonce' );
		this.estimation = racine.querySelector( '.' + RACINE + '__estimation' );
		this.precedent = racine.querySelector( '[data-action="precedent"]' );
		this.suivant = racine.querySelector( '[data-action="suivant"]' );
		this.envoyer = racine.querySelector( '[data-action="envoyer"]' );
		this.courante = 0;
		this.envoiEnCours = false;
	}

	Parcours.prototype.demarrer = function () {
		var self = this;

		this.precedent.addEventListener( 'click', function () {
			self.reculer();
		} );

		this.suivant.addEventListener( 'click', function () {
			self.avancer();
		} );

		// La touche Entrée dans un champ ne doit pas soumettre le formulaire
		// depuis une étape intermédiaire : elle avance.
		this.form.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' ) {
				return;
			}

			var cible = e.target;

			if ( cible.tagName === 'TEXTAREA' || cible.tagName === 'BUTTON' ) {
				return;
			}

			if ( self.courante < self.etapes.length - 1 ) {
				e.preventDefault();
				self.avancer();
			}
		} );

		this.form.addEventListener( 'change', function () {
			self.appliquerConditions();
			self.rafraichirEstimation();
		} );

		this.form.addEventListener( 'submit', function ( e ) {
			// Protection contre le double envoi : la garde est posée avant
			// toute autre chose.
			if ( self.envoiEnCours ) {
				e.preventDefault();
				return;
			}

			if ( ! self.validerTout() ) {
				e.preventDefault();
				return;
			}

			self.envoiEnCours = true;
			self.envoyer.disabled = true;
			self.envoyer.textContent = self.envoyer.getAttribute( 'data-envoi' ) || 'Envoi en cours…';
		} );

		this.appliquerConditions();
		this.afficher( 0, false );
		this.rafraichirEstimation();
	};

	/**
	 * Masque les champs devenus sans objet et désactive leur validation.
	 */
	Parcours.prototype.appliquerConditions = function () {
		var self = this;

		this.etapes.forEach( function ( etape ) {
			champsDe( etape ).forEach( function ( bloc ) {
				var pertinent = estPertinent( self.form, bloc );

				bloc.hidden = ! pertinent;

				// Un champ hors sujet ne part pas : `disabled` l'exclut de la
				// soumission sans effacer ce que l'utilisateur avait saisi,
				// pour le cas où il reviendrait sur sa réponse.
				Array.prototype.forEach.call(
					bloc.querySelectorAll( 'input, select, textarea' ),
					function ( c ) {
						c.disabled = ! pertinent;
					}
				);
			} );
		} );
	};

	Parcours.prototype.afficher = function ( rang, focaliser ) {
		var self = this;

		this.courante = Math.max( 0, Math.min( rang, this.etapes.length - 1 ) );

		this.etapes.forEach( function ( etape, i ) {
			etape.hidden = i !== self.courante;
		} );

		this.progression.forEach( function ( item, i ) {
			if ( i === self.courante ) {
				item.setAttribute( 'aria-current', 'step' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );

		var derniere = this.courante === this.etapes.length - 1;

		this.precedent.hidden = this.courante === 0;
		this.suivant.hidden = derniere;
		this.envoyer.hidden = ! derniere;

		if ( focaliser ) {
			var titre = this.etapes[ this.courante ].querySelector( '.' + RACINE + '__etape-titre' );

			if ( titre ) {
				titre.focus();
			}

			this.annoncer(
				'Étape ' + ( this.courante + 1 ) + ' sur ' + this.etapes.length
			);
		}
	};

	Parcours.prototype.avancer = function () {
		if ( ! this.validerEtape( this.courante ) ) {
			return;
		}

		this.afficher( this.courante + 1, true );
	};

	/**
	 * Reculer ne valide rien et n'efface rien.
	 */
	Parcours.prototype.reculer = function () {
		this.masquerErreurs();
		this.afficher( this.courante - 1, true );
	};

	Parcours.prototype.validerEtape = function ( rang ) {
		var etape = this.etapes[ rang ];
		var self = this;
		var manquants = [];

		champsDe( etape ).forEach( function ( bloc ) {
			if ( bloc.hidden ) {
				return;
			}

			var nom = bloc.getAttribute( 'data-field' );
			var definition = self.definitionDe( nom );

			if ( ! definition || ! definition.required ) {
				return;
			}

			if ( valeursDe( self.form, nom ).length === 0 ) {
				manquants.push( { bloc: bloc, nom: nom } );
			}
		} );

		this.marquer( etape, manquants );

		if ( manquants.length > 0 ) {
			this.afficherErreurs( manquants );

			return false;
		}

		this.masquerErreurs();

		return true;
	};

	Parcours.prototype.validerTout = function () {
		for ( var i = 0; i < this.etapes.length; i++ ) {
			if ( ! this.validerEtape( i ) ) {
				this.afficher( i, true );

				return false;
			}
		}

		return true;
	};

	Parcours.prototype.definitionDe = function ( nom ) {
		var trouve = null;

		( this.schema.steps || [] ).forEach( function ( etape ) {
			( etape.fields || [] ).forEach( function ( champ ) {
				if ( champ.name === nom ) {
					trouve = champ;
				}
			} );
		} );

		return trouve;
	};

	Parcours.prototype.marquer = function ( etape, manquants ) {
		var noms = manquants.map( function ( m ) {
			return m.nom;
		} );

		champsDe( etape ).forEach( function ( bloc ) {
			var enErreur = noms.indexOf( bloc.getAttribute( 'data-field' ) ) !== -1;
			var message = bloc.querySelector( '.' + RACINE + '__erreur' );

			Array.prototype.forEach.call(
				bloc.querySelectorAll( 'input, select, textarea' ),
				function ( c ) {
					if ( enErreur ) {
						c.setAttribute( 'aria-invalid', 'true' );
					} else {
						c.removeAttribute( 'aria-invalid' );
					}
				}
			);

			if ( message ) {
				message.textContent = enErreur ? 'Cette réponse est nécessaire pour continuer.' : '';
				message.hidden = ! enErreur;
			}
		} );
	};

	Parcours.prototype.afficherErreurs = function ( manquants ) {
		var liste = this.resume.querySelector( '.' + RACINE + '__erreurs-liste' );

		while ( liste.firstChild ) {
			liste.removeChild( liste.firstChild );
		}

		manquants.forEach( function ( m ) {
			var premier = m.bloc.querySelector( 'input, select, textarea' );
			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );
			var etiquette = m.bloc.querySelector( '.' + RACINE + '__label' );

			a.href = '#' + ( premier ? premier.id : '' );
			// `textContent` et jamais `innerHTML` : un libellé reste du texte.
			a.textContent = etiquette ? etiquette.textContent.trim() : m.nom;

			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				if ( premier ) {
					premier.focus();
				}
			} );

			li.appendChild( a );
			liste.appendChild( li );
		} );

		this.resume.hidden = false;
		this.resume.focus();
	};

	Parcours.prototype.masquerErreurs = function () {
		this.resume.hidden = true;
	};

	Parcours.prototype.annoncer = function ( texte ) {
		if ( this.annonce ) {
			this.annonce.textContent = texte;
		}
	};

	/**
	 * Estimation affichée, calculée depuis les tarifs **du serveur**.
	 */
	Parcours.prototype.rafraichirEstimation = function () {
		if ( ! this.estimation || ! this.schema.pricing ) {
			return;
		}

		var tarifs = this.schema.pricing;
		var choisies = valeursDe( this.form, 'options' );
		var total = tarifs.base;
		var surDevis = false;

        // Le pack remplace les prestations qu'il contient : on ne les compte
        // pas deux fois.
		var remplacees = choisies.indexOf( tarifs.pack ) !== -1 ? tarifs.packReplaces : [];

		choisies.forEach( function ( id ) {
			if ( ( tarifs.surDevis || [] ).indexOf( id ) !== -1 ) {
				surDevis = true;

				return;
			}

			if ( remplacees.indexOf( id ) !== -1 ) {
				return;
			}

			if ( typeof tarifs.options[ id ] === 'number' ) {
				total += tarifs.options[ id ];
			}
		} );

		this.estimation.textContent = surDevis
			? 'Estimation : ' + total + ' € + prestations sur devis'
			: 'Estimation : ' + total + ' €';
	};

	function demarrer() {
		var schemas = window.urbizenConception || {};

		Object.keys( schemas ).forEach( function ( id ) {
			var racine = document.getElementById( id );

			if ( racine ) {
				new Parcours( racine, schemas[ id ] ).demarrer();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
} )();
