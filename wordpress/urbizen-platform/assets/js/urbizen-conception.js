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


	/* ------------------------------------------------------------------ *
	 * Brouillon local
	 *
	 * Deux niveaux, et une règle qui les gouverne tous les deux : ce qui est
	 * écrit ne doit jamais permettre de rejouer une soumission. Ni fichier, ni
	 * nom de fichier, ni nonce, ni jeton, ni référence — rien qui identifie ou
	 * qui autorise.
	 * ------------------------------------------------------------------ */

	var JOURS = 24 * 60 * 60 * 1000;

	function Brouillon( version ) {
		this.version = String( version );
		this.cle = 'urbizen:conception:draft:v' + this.version;
		this.maxAge = 7 * JOURS;
	}

	Brouillon.prototype.disponible = function ( magasin ) {
		try {
			return !! window[ magasin ];
		} catch ( e ) {
			return false;
		}
	};

	Brouillon.prototype.ecrire = function ( magasin, charge ) {
		if ( ! this.disponible( magasin ) ) {
			return false;
		}

		try {
			window[ magasin ].setItem( this.cle, JSON.stringify( charge ) );

			return true;
		} catch ( e ) {
			// Quota atteint ou stockage refusé : le formulaire continue de
			// fonctionner, simplement sans brouillon.
			return false;
		}
	};

	Brouillon.prototype.lire = function ( magasin ) {
		if ( ! this.disponible( magasin ) ) {
			return null;
		}

		var brut;

		try {
			brut = window[ magasin ].getItem( this.cle );
		} catch ( e ) {
			return null;
		}

		if ( ! brut ) {
			return null;
		}

		var charge;

		try {
			charge = JSON.parse( brut );
		} catch ( e ) {
			// Contenu illisible : on l'efface plutôt que de le laisser traîner.
			this.effacer( magasin );

			return null;
		}

		if ( ! charge || typeof charge !== 'object' ) {
			this.effacer( magasin );

			return null;
		}

		// Un brouillon d'une autre version de schéma ne se restaure pas :
		// injecter d'anciennes valeurs dans de nouveaux champs serait pire que
		// de repartir de zéro.
		if ( String( charge.schemaVersion ) !== this.version ) {
			this.effacer( magasin );

			return { incompatible: true };
		}

		if ( magasin === 'localStorage' ) {
			var age = Date.now() - Number( charge.savedAt || 0 );

			if ( ! charge.savedAt || age > this.maxAge || age < 0 ) {
				this.effacer( magasin );

				return { expire: true };
			}
		}

		return charge;
	};

	Brouillon.prototype.effacer = function ( magasin ) {
		if ( ! this.disponible( magasin ) ) {
			return;
		}

		try {
			window[ magasin ].removeItem( this.cle );
		} catch ( e ) {
			// Rien à faire : l'absence de stockage n'est pas une erreur.
		}
	};

	Brouillon.prototype.effacerTout = function () {
		this.effacer( 'sessionStorage' );
		this.effacer( 'localStorage' );
	};

	/* ------------------------------------------------------------------ *
	 * Collection de fichiers
	 *
	 * Les objets `File` vivent ici, en mémoire, et nulle part ailleurs. On ne
	 * dépend pas de la valeur courante des `input[type=file]` : selon les
	 * navigateurs, une seconde sélection remplace la première, et un retrait
	 * ne s'y reflète pas.
	 * ------------------------------------------------------------------ */

	function Collection( contraintes ) {
		this.contraintes = contraintes;
		this.parBloc = {};
	}

	Collection.prototype.blocs = function () {
		return Object.keys( this.parBloc ).filter( function ( b ) {
			return this.parBloc[ b ].length > 0;
		}, this ).sort();
	};

	Collection.prototype.fichiers = function ( bloc ) {
		return this.parBloc[ bloc ] || [];
	};

	Collection.prototype.total = function () {
		return this.blocs().reduce( function ( n, b ) {
			return n + this.parBloc[ b ].length;
		}.bind( this ), 0 );
	};

	Collection.prototype.poids = function () {
		return this.blocs().reduce( function ( n, b ) {
			return n + this.parBloc[ b ].reduce( function ( s, f ) {
				return s + f.size;
			}, 0 );
		}.bind( this ), 0 );
	};

	/**
	 * Ajoute des fichiers à un bloc, et rend les refus motivés.
	 */
	Collection.prototype.ajouter = function ( bloc, fichiers ) {
		var c = this.contraintes;
		var refus = [];

		if ( ! this.parBloc[ bloc ] ) {
			this.parBloc[ bloc ] = [];
		}

		Array.prototype.forEach.call( fichiers, function ( f ) {
			var extension = ( f.name.split( '.' ).pop() || '' ).toLowerCase();

			if ( c.extensions.indexOf( extension ) === -1 ) {
				refus.push( { fichier: f.name, motif: 'format' } );

				return;
			}

			if ( f.size > c.maxFileSize ) {
				refus.push( { fichier: f.name, motif: 'taille' } );

				return;
			}

			if ( this.parBloc[ bloc ].length >= c.maxPerBlock ) {
				refus.push( { fichier: f.name, motif: 'nombre-bloc' } );

				return;
			}

			if ( this.total() >= c.maxTotal ) {
				refus.push( { fichier: f.name, motif: 'nombre-total' } );

				return;
			}

			if ( this.poids() + f.size > c.maxTotalSize ) {
				refus.push( { fichier: f.name, motif: 'poids-total' } );

				return;
			}

			this.parBloc[ bloc ].push( f );
		}, this );

		return refus;
	};

	Collection.prototype.retirer = function ( bloc, index ) {
		if ( this.parBloc[ bloc ] ) {
			this.parBloc[ bloc ].splice( index, 1 );
		}
	};

	Collection.prototype.vider = function () {
		this.parBloc = {};
	};

	/**
	 * Manifeste : des décomptes, et rien d'autre.
	 *
	 * Ni nom, ni extension, ni type, ni date, ni empreinte. Le serveur s'en
	 * sert pour constater une réception partielle, jamais pour accorder sa
	 * confiance.
	 */
	Collection.prototype.manifeste = function () {
		var blocks = {};
		var self = this;

		this.blocs().forEach( function ( b ) {
			blocks[ b ] = {
				count: self.parBloc[ b ].length,
				size: self.parBloc[ b ].reduce( function ( s, f ) {
					return s + f.size;
				}, 0 )
			};
		} );

		return {
			version: 1,
			total_count: this.total(),
			total_size: this.poids(),
			blocks: blocks
		};
	};

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

		this.brouillon = new Brouillon( schema.version );
		this.fichiers = new Collection( schema.uploads );
		this.consentement = racine.querySelector( '[data-role="consentement-brouillon"]' );
		this.info = racine.querySelector( '[data-role="info-brouillon"]' );
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
			// L'envoi est toujours pris en charge ici : jamais de soumission
			// native, qui enverrait les `input[type=file]` plutôt que la
			// collection interne, et sans manifeste.
			e.preventDefault();
			self.envoyerFormulaire();
		} );

		// Le script est là : la navigation peut apparaître.
		var nav = this.racine.querySelector( '.' + RACINE + '__navigation' );

		if ( nav ) {
			nav.hidden = false;
		}

		this.brancherFichiers();
		this.brancherBrouillon();
		this.restaurer();
		this.appliquerConditions();
		this.afficher( this.courante, false );
		this.rafraichirEstimation();
	};

	/* -------------------------------------------------------------- *
	 * Fichiers
	 * -------------------------------------------------------------- */

	Parcours.prototype.brancherFichiers = function () {
		var self = this;

		Array.prototype.forEach.call(
			this.form.querySelectorAll( 'input[type="file"]' ),
			function ( input ) {
				var bloc = input.name.replace( /\[\]$/, '' );

				input.addEventListener( 'change', function () {
					var refus = self.fichiers.ajouter( bloc, input.files );

					// L'input est vidé : c'est la collection interne qui fait
					// foi, et une seconde sélection doit s'ajouter, pas
					// remplacer.
					input.value = '';

					self.listerFichiers( bloc );
					self.signalerRefus( bloc, refus );
				} );
			}
		);
	};

	Parcours.prototype.listerFichiers = function ( bloc ) {
		var liste = this.form.querySelector( '[data-bloc="' + bloc + '"]' );

		if ( ! liste ) {
			return;
		}

		var self = this;

		while ( liste.firstChild ) {
			liste.removeChild( liste.firstChild );
		}

		this.fichiers.fichiers( bloc ).forEach( function ( f, i ) {
			var li = document.createElement( 'li' );
			var nom = document.createElement( 'span' );
			var taille = document.createElement( 'span' );
			var retirer = document.createElement( 'button' );

			// `textContent` : un nom de document reste du texte, jamais du HTML.
			nom.textContent = f.name;
			taille.textContent = Math.round( f.size / 1024 ) + ' Ko';
			retirer.type = 'button';
			retirer.textContent = 'Retirer';
			retirer.setAttribute( 'aria-label', 'Retirer ' + f.name );

			retirer.addEventListener( 'click', function () {
				self.fichiers.retirer( bloc, i );
				self.listerFichiers( bloc );
				self.annoncer( f.name + ' retiré.' );
			} );

			li.appendChild( nom );
			li.appendChild( taille );
			li.appendChild( retirer );
			liste.appendChild( li );
		} );

		this.annoncer(
			this.fichiers.total() + ' document(s) sélectionné(s), ' +
				Math.round( this.fichiers.poids() / 1024 ) + ' Ko au total.'
		);
	};

	Parcours.prototype.signalerRefus = function ( bloc, refus ) {
		if ( ! refus.length ) {
			return;
		}

		var motifs = {
			format: 'format non accepté',
			taille: 'document trop volumineux',
			'nombre-bloc': 'trop de documents dans cette rubrique',
			'nombre-total': 'trop de documents au total',
			'poids-total': 'poids total dépassé'
		};

		var textes = refus.map( function ( r ) {
			return r.fichier + ' : ' + ( motifs[ r.motif ] || 'refusé' );
		} );

		this.annoncer( textes.join( ' — ' ) );
	};

	/* -------------------------------------------------------------- *
	 * Brouillon
	 * -------------------------------------------------------------- */

	Parcours.prototype.brancherBrouillon = function () {
		var self = this;

		this.form.addEventListener( 'input', function () {
			self.sauvegarder();
		} );

		this.form.addEventListener( 'change', function () {
			self.sauvegarder();
		} );

		if ( this.consentement ) {
			this.consentement.addEventListener( 'change', function () {
				if ( self.consentement.checked ) {
					self.sauvegarder();
					self.informer( 'Vos réponses seront conservées sur cet appareil pendant 7 jours.' );
				} else {
					// Retrait du consentement : l'effacement est immédiat.
					self.brouillon.effacer( 'localStorage' );
					self.informer( 'Sauvegarde sur cet appareil désactivée et effacée.' );
				}
			} );
		}

		var effacer = this.racine.querySelector( '[data-action="effacer-brouillon"]' );

		if ( effacer ) {
			effacer.addEventListener( 'click', function () {
				self.brouillon.effacerTout();
				self.informer( 'Brouillon supprimé.' );
			} );
		}
	};

	/**
	 * Valeurs restaurables. Les champs de sécurité et les fichiers en sont
	 * exclus par construction : on n'itère que sur les champs du schéma.
	 */
	Parcours.prototype.valeurs = function () {
		var valeurs = {};
		var self = this;

		( this.schema.steps || [] ).forEach( function ( etape ) {
			( etape.fields || [] ).forEach( function ( champ ) {
				if ( champ.type === 'file' ) {
					return;
				}

				var v = valeursDe( self.form, champ.name );

				if ( v.length ) {
					valeurs[ champ.name ] = v;
				}
			} );
		} );

		return valeurs;
	};

	Parcours.prototype.charge = function () {
		return {
			schemaVersion: this.schema.version,
			savedAt: Date.now(),
			step: this.courante,
			values: this.valeurs(),
			persist: !! ( this.consentement && this.consentement.checked )
		};
	};

	Parcours.prototype.sauvegarder = function () {
		var charge = this.charge();

		this.brouillon.ecrire( 'sessionStorage', charge );

		// Rien n'est écrit durablement sans consentement explicite.
		if ( charge.persist ) {
			this.brouillon.ecrire( 'localStorage', charge );
		}
	};

	Parcours.prototype.restaurer = function () {
		var session = this.brouillon.lire( 'sessionStorage' );
		var charge = session;

		// Un brouillon de session inexploitable ne doit pas faire oublier ce
		// qu'il était : on retient le motif avant de tenter l'autre magasin.
		var incompatible = !! ( session && session.incompatible );
		var expire = !! ( session && session.expire );

		if ( ! charge || charge.incompatible || charge.expire ) {
			charge = this.brouillon.lire( 'localStorage' );
			incompatible = incompatible || !! ( charge && charge.incompatible );
			expire = expire || !! ( charge && charge.expire );
		}

		if ( ! charge || charge.incompatible || charge.expire ) {
			if ( incompatible ) {
				this.informer( 'Un brouillon d’une version antérieure a été trouvé et supprimé : le formulaire repart vierge.' );
			} else if ( expire ) {
				this.informer( 'Votre brouillon a expiré et a été supprimé.' );
			}

			return;
		}

		var self = this;

		Object.keys( charge.values || {} ).forEach( function ( nom ) {
			self.appliquerValeur( nom, charge.values[ nom ] );
		} );

		if ( this.consentement && charge.persist ) {
			this.consentement.checked = true;
		}

		this.courante = Math.min( Number( charge.step ) || 0, this.etapes.length - 1 );

		// Les objets File ne se recréent pas : on le dit, plutôt que de laisser
		// croire que les documents sont toujours joints.
		this.informer( 'Vos réponses ont été restaurées. Les documents doivent être sélectionnés à nouveau.' );
	};

	Parcours.prototype.appliquerValeur = function ( nom, valeurs ) {
		var controles = this.form.querySelectorAll(
			'[name="' + nom + '"], [name="' + nom + '[]"]'
		);

		Array.prototype.forEach.call( controles, function ( c ) {
			if ( c.type === 'file' ) {
				return;
			}

			if ( c.type === 'checkbox' || c.type === 'radio' ) {
				c.checked = valeurs.indexOf( c.value ) !== -1;
			} else {
				c.value = valeurs[ 0 ];
			}
		} );
	};

	Parcours.prototype.informer = function ( texte ) {
		if ( this.info ) {
			this.info.textContent = texte;
		}

		this.annoncer( texte );
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

		// Les deux champs de la définition serveur : les prestations tarifées
		// et celles qui relèvent d'un devis. Lire un champ « options » qui
		// n'existe pas laissait l'estimation figée sur le prix de base.
		var choisies = valeursDe( this.form, 'options_tarifees' );
		var devis = valeursDe( this.form, 'options_sur_devis' );
		var total = tarifs.base;
		var surDevis = devis.length > 0;

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

	/* ------------------------------------------------------------------ *
	 * Soumission
	 * ------------------------------------------------------------------ */

	/**
	 * Construit le FormData transmis.
	 *
	 * Les champs inapplicables sont `disabled`, donc absents. Les documents
	 * viennent de la collection interne, sous le nom exact attendu par le
	 * serveur. Les champs de sécurité sont relus dans le DOM au dernier
	 * moment — ils ne transitent jamais par le brouillon.
	 */
	Parcours.prototype.construireFormData = function () {
		var donnees = new FormData();
		var self = this;

		Array.prototype.forEach.call(
			this.form.querySelectorAll( 'input, select, textarea' ),
			function ( c ) {
				if ( c.disabled || c.type === 'file' ) {
					return;
				}

				if ( ( c.type === 'checkbox' || c.type === 'radio' ) && ! c.checked ) {
					return;
				}

				donnees.append( c.name, c.value );
			}
		);

		this.fichiers.blocs().forEach( function ( bloc ) {
			self.fichiers.fichiers( bloc ).forEach( function ( f ) {
				donnees.append( bloc + '[]', f, f.name );
			} );
		} );

		donnees.append( 'urbizen_manifest', JSON.stringify( this.fichiers.manifeste() ) );

		return donnees;
	};

	Parcours.prototype.envoyerFormulaire = function () {
		var self = this;

		if ( this.envoiEnCours ) {
			return;
		}

		this.appliquerConditions();

		if ( ! this.validerTout() ) {
			return;
		}

		this.envoiEnCours = true;
		this.envoyer.disabled = true;
		this.envoyer.textContent = 'Envoi en cours…';

		fetch( this.form.action, {
			method: 'POST',
			body: this.construireFormData(),
			credentials: 'same-origin',
			redirect: 'follow'
		} )
			.then( function ( reponse ) {
				// **Un 200 ne prouve rien.** Le contrôleur répond par une
				// redirection, et une redirection d'erreur mène tout autant à
				// une page en 200. Le seul verdict est le marqueur posé par le
				// serveur dans l'URL finale.
				return self.verdict( reponse );
			} )
			.then( function ( succes ) {
				if ( succes ) {
					self.apresSucces();
				} else {
					self.apresEchec( 'Votre demande n’a pas pu être enregistrée. Vérifiez vos réponses et réessayez.' );
				}
			} )
			.catch( function () {
				self.apresEchec( 'L’envoi a échoué. Vos réponses sont conservées : réessayez dans un instant.' );
			} );
	};

	/**
	 * Lit le marqueur de résultat dans l'URL finale.
	 */
	Parcours.prototype.verdict = function ( reponse ) {
		var url = reponse && reponse.url ? String( reponse.url ) : '';

		if ( url.indexOf( 'urbizen_submission=success' ) !== -1 ) {
			return true;
		}

		if ( url.indexOf( 'urbizen_submission=error' ) !== -1 ) {
			return false;
		}

		// Aucun marqueur : on ne devine pas. Un doute vaut un échec.
		return false;
	};

	Parcours.prototype.apresSucces = function () {
		this.brouillon.effacerTout();
		this.fichiers.vider();
		this.envoyer.textContent = 'Demande envoyée';
		this.informer( 'Votre demande a bien été envoyée. Nous revenons vers vous rapidement.' );

		// Le bouton reste désactivé : plus de seconde soumission.
		this.envoiEnCours = true;
	};

	Parcours.prototype.apresEchec = function ( message ) {
		this.envoiEnCours = false;
		this.envoyer.disabled = false;
		this.envoyer.textContent = 'Envoyer ma demande';

		// Les brouillons et les fichiers restent : c'est tout l'intérêt.
		this.informer( message );
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
