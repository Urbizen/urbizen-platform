/* Qualification d'urbanisme — quelle formalité pour ce projet ?
   ==========================================================================

   Ce module remplace un défaut qui envoyait implicitement en déclaration
   préalable tout projet non explicitement mappé. Une extension de soixante
   mètres carrés y tombait comme un ravalement. Le
   libellé affiché parlait pourtant d'« orientation proposée », donnant à un
   défaut technique l'apparence d'une étude.

   Principe
   --------

   Le moteur ne devine jamais. Il rend cinq états et rien d'autre :

     dp         déclaration préalable suffisamment établie
     pcmi       permis de construire suffisamment établi
     none       aucune formalité selon les règles modélisées
     confirm    informations insuffisantes, ou règle locale nécessaire
     conception parcours commercial, hors qualification d'urbanisme

   `confirm` n'est pas un échec : c'est la réponse juste quand la donnée
   manque. Mieux vaut annoncer une vérification qu'une mauvaise DP.

   Sources
   -------

   Les seuils viennent du Code de l'urbanisme, vérifiés sur Légifrance et non
   de mémoire. Chaque décision porte l'article qui la fonde, dans `rule`.

     R.421-2   constructions nouvelles dispensées de toute formalité
     R.421-9   constructions nouvelles soumises à déclaration préalable
     R.421-14  travaux sur constructions existantes soumis à permis
     R.421-17  travaux sur constructions existantes soumis à déclaration
     R.431-2   dispense d'architecte, et son plafond de 150 m²

   Deux avertissements sur R.431-2. D'abord il sert ici à deux choses
   distinctes : le recours à l'architecte, et — parce que R.421-14 b) y
   renvoie — la bascule en permis d'une création de 20 à 40 m² en zone
   urbaine. Ce module ne traite QUE la seconde. L'obligation d'architecte
   reste où elle est, dans le formulaire de permis. Ensuite le plafond de
   150 m² ne vaut que pour une personne physique construisant pour elle-même
   une construction non agricole ; hors de ce cas, le moteur rend `confirm`.

   Ce que ce module refuse de trancher
   -----------------------------------

   Les secteurs patrimoniaux remarquables, les abords de monuments historiques
   et les sites classés retirent le bénéfice des dispenses de R.421-2 et
   R.421-9. Le moteur ne sait pas les détecter : quand le résultat en dépend,
   il rend `confirm`. De même pour un PLU localement plus strict, qu'aucun
   seuil national ne permet de connaître.

   Jumeau PHP
   ----------

   `QualificationUrbanisme.php` applique les mêmes règles côté serveur. Les
   deux ne partagent pas de code — l'un tourne dans un navigateur, l'autre
   dans WordPress — mais ils partagent un corpus de cas, `tests/qualification/
   cas.json`, que les deux bancs rejouent en exigeant des verdicts identiques.
   Une divergence est un échec de test, pas une découverte de production. */

(function (racine, fabrique) {
	if (typeof module === "object" && module.exports) { module.exports = fabrique(); }
	else { racine.UrbizenQualification = fabrique(); }
}(typeof self !== "undefined" ? self : this, function () {
	"use strict";

	/* Seuils, en mètres carrés et en mètres. Un seul endroit. */
	var S = {
		DISPENSE: 5,          // R.421-2  : emprise ET surface de plancher
		NOUVELLE_DP: 20,      // R.421-9  : emprise ET surface de plancher
		HAUTEUR_NOUVELLE: 12, // R.421-9  et R.421-2
		EXISTANT_PC: 20,      // R.421-14 a)
		EXISTANT_PC_ZONE_U: 40, // R.421-14 b)
		TOTAL_R431_2: 150,    // R.431-2  a) — personne physique, non agricole
		EXISTANT_DP: 5,       // R.421-17 f) et g)
		BASSIN_DISPENSE: 10,  // R.421-2
		BASSIN_DP: 100,       // R.421-9
		COUVERTURE_PISCINE: 1.8 // R.421-9 — hauteur de couverture
	};

	function resultat(status, rule, reason, missing) {
		return { status: status, rule: rule || null, reason: reason || "", missing: missing || [] };
	}

	/** Une valeur numérique utilisable, ou `null` si elle n'a pas été fournie. */
	function nombre(v) {
		if (v === null || v === undefined || v === "" || typeof v === "boolean") { return null; }
		var n;
		if (typeof v === "number") {
			n = v;
		} else if (typeof v === "string") {
			var texte = v.trim();
			if (!/^(?:\d+(?:[.,]\d*)?|[.,]\d+)$/.test(texte)) { return null; }
			n = Number(texte.replace(",", "."));
		} else {
			return null;
		}
		return (typeof n === "number" && isFinite(n) && n >= 0) ? n : null;
	}

	/** Les seuils regardent la plus grande mesure, mais une petite valeur connue
	    ne permet jamais de supposer que l'autre vaut zéro. */
	function mesuresCreation(d) {
		return { sp: nombre(d.sp_creee), em: nombre(d.emprise_creee) };
	}

	function creation(d) {
		var mesures = mesuresCreation(d);
		if (mesures.sp === null && mesures.em === null) { return null; }
		return Math.max(mesures.sp === null ? 0 : mesures.sp, mesures.em === null ? 0 : mesures.em);
	}

	function mesuresManquantes(mesures) {
		var manque = [];
		if (mesures.sp === null) { manque.push("sp_creee"); }
		if (mesures.em === null) { manque.push("emprise_creee"); }
		return manque;
	}

	/* Un secteur protégé retire le bénéfice des dispenses. Tant qu'on ne sait
	   pas, on ne peut pas prononcer `none` ni s'appuyer sur R.421-9. */
	function secteurBloquant(d) {
		return d.secteur_protege !== false;
	}

	/* ---------------------------------------------- travaux sur l'existant --
	   Extension explicitement qualifiée, et transformation d'un garage. */
	function surExistant(d, etiquette) {
		var mesures = mesuresCreation(d);
		var cree = creation(d);
		if (cree === null) {
			return resultat("confirm", "R.421-14", etiquette + " : la surface créée n'est pas connue.", ["sp_creee", "emprise_creee"]);
		}

		if (cree > S.EXISTANT_PC_ZONE_U) {
			return resultat("pcmi", "R.421-14 a)", "Création de " + cree + " m² : au-delà de 40 m², le permis est exigé quelle que soit la zone.");
		}

		var manqueMesure = mesuresManquantes(mesures);
		if (manqueMesure.length) {
			return resultat("confirm", "R.421-14 / R.421-17", etiquette + " : surface de plancher et emprise au sol sont nécessaires tant que le seuil du permis n'est pas déjà dépassé.", manqueMesure);
		}

		if (cree <= S.EXISTANT_DP) {
			// R.421-17 f) ne vise que les créations de plus de 5 m². En dessous,
			// une déclaration peut rester due au titre de l'aspect extérieur —
			// ce que ce moteur ne sait pas juger.
			return resultat("confirm", "R.421-17", "Création de " + cree + " m² : sous le seuil de 5 m², la formalité dépend de l'aspect extérieur des travaux.", ["aspect_exterieur"]);
		}

		var zone = d.zone_u;
		var enZoneU = zone === true;
		var horsZoneU = zone === false;

		if (cree > S.EXISTANT_PC) {
			// Entre 20 et 40 m², tout dépend de la zone.
			if (horsZoneU) {
				return resultat("pcmi", "R.421-14 a)", "Création de " + cree + " m² hors zone urbaine : au-delà de 20 m², le permis est exigé.");
			}
			if (!enZoneU) {
				return resultat("confirm", "R.421-14 b)", "Création de " + cree + " m² : entre 20 et 40 m², la formalité dépend de la zone du document d'urbanisme.", ["zone_u"]);
			}
			// Zone urbaine, 20 à 40 m² : l'exception de R.421-14 b) s'applique si
			// l'opération porte le total au-delà du plafond de R.431-2.
			var total = nombre(d.sp_totale);
			if (total === null) {
				return resultat("confirm", "R.421-14 b)", "Création de " + cree + " m² en zone urbaine : la surface totale après travaux décide entre déclaration et permis.", ["sp_totale"]);
			}
			if (d.personne_physique !== true || d.usage_agricole !== false) {
				return resultat("confirm", "R.431-2", "Le plafond de 150 m² ne vaut que pour une personne physique construisant pour elle-même une construction non agricole.", ["personne_physique", "usage_agricole"]);
			}
			if (total > S.TOTAL_R431_2) {
				return resultat("pcmi", "R.421-14 b) + R.431-2", "Création de " + cree + " m² en zone urbaine portant la surface totale à " + total + " m², au-delà de 150 m² : le permis est exigé.");
			}
			return resultat("dp", "R.421-17 f)", "Création de " + cree + " m² en zone urbaine, surface totale de " + total + " m² : la déclaration préalable suffit.");
		}

		// Création de 5 à 20 m² : sous le seuil du permis dans les deux zones.
		return resultat("dp", "R.421-17 f)", "Création de " + cree + " m² : sous le seuil du permis, la déclaration préalable s'applique.");
	}

	/* ------------------------------------------- construction indépendante --
	   Garage ou abri autonome : ce ne sont plus des travaux sur l'existant. */
	function constructionNouvelle(d, etiquette) {
		var mesures = mesuresCreation(d);
		var cree = creation(d);
		if (cree === null) {
			return resultat("confirm", "R.421-9", etiquette + " : la surface créée n'est pas connue.", ["sp_creee", "emprise_creee"]);
		}

		var hauteur = nombre(d.hauteur_m);

		if (cree > S.NOUVELLE_DP) {
			return resultat("pcmi", "R.421-1", "Construction indépendante de " + cree + " m² : au-delà de 20 m², le permis est exigé.");
		}

		var manqueMesure = mesuresManquantes(mesures);
		if (manqueMesure.length) {
			return resultat("confirm", "R.421-2 / R.421-9", etiquette + " : surface de plancher et emprise au sol sont nécessaires pour vérifier les plafonds de 5 et 20 m².", manqueMesure);
		}

		if (secteurBloquant(d)) {
			return resultat("confirm", "R.421-2 / R.421-9", "En secteur patrimonial remarquable, aux abords d'un monument historique ou en site classé, les dispenses ne s'appliquent pas.", ["secteur_protege"]);
		}

		if (hauteur === null) {
			return resultat("confirm", "R.421-9", "La hauteur de la construction conditionne le régime.", ["hauteur_m"]);
		}

		if (hauteur > S.HAUTEUR_NOUVELLE) {
			if (cree <= S.DISPENSE) {
				return resultat("dp", "R.421-9 c)", "Construction de " + cree + " m² et de " + hauteur + " m de haut : au-delà de 12 m et jusqu'à 5 m², une déclaration préalable est requise.");
			}
			return resultat("pcmi", "R.421-1 / R.421-9", "Construction de " + cree + " m² et de " + hauteur + " m de haut : au-delà de 12 m et de 5 m², elle ne relève plus des cas soumis à déclaration préalable.");
		}

		if (cree <= S.DISPENSE) {
			return resultat("none", "R.421-2", "Construction indépendante de " + cree + " m² et de " + hauteur + " m de haut : aucune formalité au titre du code de l'urbanisme.");
		}

		return resultat("dp", "R.421-9", "Construction indépendante de " + cree + " m² et de " + hauteur + " m de haut : déclaration préalable.");
	}

	/* ------------------------------------------------------------ piscine -- */
	function piscine(d) {
		var bassin = nombre(d.bassin_m2);
		if (bassin === null) {
			return resultat("confirm", "R.421-9", "La superficie du bassin décide de la formalité.", ["bassin_m2"]);
		}

		if (bassin > S.BASSIN_DP) {
			return resultat("pcmi", "R.421-9", "Bassin de " + bassin + " m² : au-delà de 100 m², le permis est exigé.");
		}

		if (d.couverte !== true && d.couverte !== false) {
			return resultat("confirm", "R.421-9", "La présence d'une couverture peut modifier la formalité applicable.", ["couverte"]);
		}

		var couverture = nombre(d.hauteur_couverture_m);
		var couverte = d.couverte === true;

		if (couverte && couverture === null) {
			return resultat("confirm", "R.421-9", "La hauteur de la couverture décide entre déclaration et permis.", ["hauteur_couverture_m"]);
		}

		if (couverte && couverture >= S.COUVERTURE_PISCINE) {
			return resultat("pcmi", "R.421-9", "Couverture de " + couverture + " m : à partir de 1,80 m, la piscine relève du permis.");
		}

		if (bassin <= S.BASSIN_DISPENSE) {
			if (secteurBloquant(d)) {
				return resultat("confirm", "R.421-2", "Bassin de " + bassin + " m² : la dispense tombe en secteur protégé.", ["secteur_protege"]);
			}
			return resultat("none", "R.421-2", "Bassin de " + bassin + " m² : aucune formalité au titre du code de l'urbanisme.");
		}

		return resultat("dp", "R.421-9", "Bassin de " + bassin + " m² : déclaration préalable.");
	}

	/* --------------------------------------------- transformer un existant --

	   Le seul cas assez documenté pour conclure est le garage de stationnement
	   accessoire à une maison : il est exclu de la surface de plancher, mais suit
	   déjà la destination habitation. Pour des combles, un sous-sol ou une
	   dépendance, la hauteur seule ne permet pas d'écarter les autres exclusions
	   de R.111-22. Pour un bâtiment séparé, il faut en plus connaître sa
	   destination actuelle, sa destination future et son statut accessoire. */

	function transformation(d) {
		/* Une transformation dans l'enveloppe existante crée éventuellement de la
		   surface de plancher, mais pas une emprise nouvelle. */
		var cree = nombre(d.sp_creee);
		if (cree === null) {
			return resultat("confirm", "R.421-17 g)", "La surface transformée n'est pas connue.", ["sp_creee"]);
		}

		if (d.ferme_couvert === false) {
			// Sans espace clos et couvert, ce n'est pas la transformation de
			// R.421-17 g) : on ne sait pas ce que c'est.
			return resultat("confirm", "R.421-17", "Un espace qui n'est pas clos et couvert ne relève pas de la transformation prévue par le code : le projet doit être décrit.", ["description"]);
		}
		if (d.ferme_couvert !== true) {
			return resultat("confirm", "R.421-17 g)", "L'espace transformé doit être clos et couvert pour relever de cette règle.", ["ferme_couvert"]);
		}

		if (!d.local_actuel) {
			return resultat("confirm", "R.421-17 g)", "La nature du local transformé décide du régime applicable.", ["local_actuel"]);
		}

		if (d.local_rattache === "batiment_separe") {
			return resultat("confirm", "R.151-27 à R.151-29", "Un bâtiment séparé peut rester accessoire de la construction principale et un changement de destination exige de connaître les destinations actuelle et future.", ["destination_actuelle", "destination_future", "statut_accessoire"]);
		} else if (d.local_rattache !== "maison") {
			return resultat("confirm", "R.421-14 c)", "Un local rattaché au logement en suit la destination ; un bâtiment séparé a la sienne.", ["local_rattache"]);
		}

		/* Le garage affecté au stationnement est le seul sous-parcours pour lequel
		   le tunnel dispose de faits suffisants sur la surface de plancher. */
		if (d.local_actuel !== "garage") {
			return resultat("confirm", "R.111-22", "La hauteur ne suffit pas à savoir si des combles, un sous-sol ou une dépendance comptent déjà dans la surface de plancher.", ["surface_deja_plancher"]);
		}

		/* R.421-17 g) : plus de cinq mètres carrés de surface close et
		   couverte deviennent de la surface de plancher. Au-delà des seuils de
		   R.421-14, le permis reprend la main. */
		if (cree <= S.EXISTANT_DP) {
			if (d.modifie_aspect_exterieur === true) {
				return resultat("dp", "R.421-17 a)", "Transformation de " + cree + " m² avec modification de l'aspect extérieur : déclaration préalable.");
			}
			if (d.modifie_aspect_exterieur === false) {
				return resultat("none", "R.421-17", "Transformation de " + cree + " m² sans modification extérieure : aucune formalité au titre du code de l'urbanisme.");
			}
			return resultat("confirm", "R.421-17 g)", "Transformation de " + cree + " m² : sous le seuil de 5 m², la formalité dépend des travaux extérieurs.", ["modifie_aspect_exterieur"]);
		}

		var donneesSeuils = {};
		for (var cle in d) {
			if (Object.prototype.hasOwnProperty.call(d, cle)) { donneesSeuils[cle] = d[cle]; }
		}
		donneesSeuils.emprise_creee = 0;
		var surSeuils = surExistant(donneesSeuils, "Transformation");
		if (surSeuils.status === "dp") {
			return resultat("dp", "R.421-17 g)", "Transformation de " + cree + " m² de surface close et couverte en surface de plancher : déclaration préalable.");
		}
		return surSeuils;
	}

	/* ----------------------------------------------------------- le routeur -- */

	/* Chaque projet a une entrée EXPLICITE. Aucun défaut, aucun `||`. Un projet
	   absent de cette table est une erreur de programmation, pas une DP. */
	var PARCOURS = {
		extension: function (d) { return surExistant(d, "Extension"); },

		garage: function (d) {
			if (creation(d) > S.EXISTANT_PC_ZONE_U) { return resultat("pcmi", "R.421-1 / R.421-14", "Au-delà de 40 m², le permis est exigé que le garage soit une construction nouvelle ou une extension."); }
			if (d.implantation === "accole") { return resultat("confirm", "R.421-9 / R.421-14", "Le fait de toucher le bâtiment ne suffit pas à qualifier juridiquement le garage d'extension.", ["qualification_plu"]); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Garage indépendant"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Accolé à une construction existante ou indépendant : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		abri: function (d) {
			if (creation(d) > S.EXISTANT_PC_ZONE_U) { return resultat("pcmi", "R.421-1 / R.421-14", "Au-delà de 40 m², le permis est exigé que l'abri soit une construction nouvelle ou une extension."); }
			if (d.implantation === "accole") { return resultat("confirm", "R.421-9 / R.421-14", "Le fait de toucher le bâtiment ne suffit pas à qualifier juridiquement l'abri d'extension.", ["qualification_plu"]); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Abri indépendant"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Accolé à une construction existante ou indépendant : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		piscine: piscine,

		/* Une pergola ouverte ne crée pas de surface de plancher, seulement une
		   emprise. Aucun article ne la nomme : elle suit le régime commun des
		   constructions, sans que le seul adossement suffise à la qualifier. */
		pergola: function (d) {
			if (creation(d) > S.EXISTANT_PC_ZONE_U) { return resultat("pcmi", "R.421-1 / R.421-14", "Au-delà de 40 m², le permis est exigé que la pergola soit une construction nouvelle ou une extension."); }
			if (d.implantation === "accole") { return resultat("confirm", "R.421-9 / R.421-14", "Une pergola adossée peut être traitée comme construction nouvelle ou extension selon sa conception et le document d'urbanisme.", ["qualification_plu"]); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Pergola autonome"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Adossée à la construction ou autonome : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		transformation: transformation,

		/* Les cartes « façade », « toiture » et « solaire » sont trop larges pour
		   trancher sans description : entretien à l'identique, installation au sol,
		   changement de destination et structure n'ont pas le même régime. */
		facade: function (d) {
			if (d.modifie_structure_ou_facade === true && d.changement_destination === true) {
				return resultat("pcmi", "R.421-14 c)", "Modification de la façade accompagnant un changement de destination : permis de construire.");
			}
			return resultat("confirm", "R.421-2 / R.421-17", "Il faut distinguer l'entretien à l'identique d'une modification de l'aspect extérieur et d'un changement de destination.", ["description"]);
		},

		toiture: function () {
			return resultat("confirm", "R.421-2 / R.421-17", "Il faut distinguer l'entretien à l'identique d'une modification de l'aspect extérieur, du volume ou de la structure.", ["description"]);
		},

		solaire: function () {
			return resultat("confirm", "R.421-2 / R.421-9 / R.421-17", "La formalité dépend notamment d'une pose en toiture ou au sol, de la hauteur, de la puissance et du secteur.", ["description"]);
		},

		maison: function () {
			return resultat("pcmi", "R.421-1", "Construction d'une maison individuelle : permis de construire.");
		},

		conception: function () {
			return resultat("conception", null, "Prestation de plans sur mesure : hors qualification d'urbanisme.");
		},

		/* Un projet non identifié ne peut pas être qualifié. C'est le cas qui a
		   le plus souffert du défaut précédent : « Autre » partait en DP. */
		autre: function () {
			return resultat("confirm", null, "Le projet doit être décrit avant de déterminer la formalité applicable.", ["description"]);
		}
	};

	function qualifyProject(donnees) {
		var d = donnees || {};
		var parcours = Object.prototype.hasOwnProperty.call(PARCOURS, d.projet) ? PARCOURS[d.projet] : null;

		if (!parcours) {
			return resultat("confirm", null, "Type de projet inconnu du moteur de qualification.", ["projet"]);
		}

		return parcours(d);
	}

	return {
		qualifyProject: qualifyProject,
		SEUILS: S,
		PROJETS: Object.keys(PARCOURS),
		ETATS: ["dp", "pcmi", "none", "confirm", "conception"]
	};
}));
