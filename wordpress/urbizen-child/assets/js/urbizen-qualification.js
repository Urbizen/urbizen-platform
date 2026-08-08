/* Qualification d'urbanisme — quelle formalité pour ce projet ?
   ==========================================================================

   Ce module remplace un défaut qui envoyait en déclaration préalable tout
   projet non explicitement mappé :

       return FORM_BY_PROJECT[project] || "dp";

   Une extension de soixante mètres carrés y tombait comme un ravalement. Le
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
		if (v === null || v === undefined || v === "") { return null; }
		var n = typeof v === "number" ? v : parseFloat(String(v).replace(",", "."));
		return (typeof n === "number" && isFinite(n) && n >= 0) ? n : null;
	}

	/** La plus grande des deux mesures : c'est elle que les seuils regardent. */
	function creation(d) {
		var sp = nombre(d.sp_creee), em = nombre(d.emprise_creee);
		if (sp === null && em === null) { return null; }
		return Math.max(sp === null ? 0 : sp, em === null ? 0 : em);
	}

	/* Un secteur protégé retire le bénéfice des dispenses. Tant qu'on ne sait
	   pas, on ne peut pas prononcer `none` ni s'appuyer sur R.421-9. */
	function secteurBloquant(d) {
		return d.secteur_protege === true || d.secteur_protege === undefined || d.secteur_protege === null || d.secteur_protege === "unknown";
	}

	/* ---------------------------------------------- travaux sur l'existant --
	   Extension, garage ou abri accolé, transformation. */
	function surExistant(d, etiquette) {
		var cree = creation(d);
		if (cree === null) {
			return resultat("confirm", "R.421-14", etiquette + " : la surface créée n'est pas connue.", ["sp_creee", "emprise_creee"]);
		}

		if (cree > S.EXISTANT_PC_ZONE_U) {
			return resultat("pcmi", "R.421-14 a)", "Création de " + cree + " m² : au-delà de 40 m², le permis est exigé quelle que soit la zone.");
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
			if (d.personne_physique === false || d.usage_agricole === true) {
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
		var cree = creation(d);
		if (cree === null) {
			return resultat("confirm", "R.421-9", etiquette + " : la surface créée n'est pas connue.", ["sp_creee", "emprise_creee"]);
		}

		var hauteur = nombre(d.hauteur_m);

		if (cree > S.NOUVELLE_DP) {
			return resultat("pcmi", "R.421-1", "Construction indépendante de " + cree + " m² : au-delà de 20 m², le permis est exigé.");
		}

		if (hauteur !== null && hauteur > S.HAUTEUR_NOUVELLE) {
			return resultat("pcmi", "R.421-9", "Hauteur de " + hauteur + " m : au-delà de 12 m, la construction sort du champ de la déclaration.");
		}

		if (secteurBloquant(d)) {
			return resultat("confirm", "R.421-2 / R.421-9", "En secteur patrimonial remarquable, aux abords d'un monument historique ou en site classé, les dispenses ne s'appliquent pas.", ["secteur_protege"]);
		}

		if (hauteur === null) {
			return resultat("confirm", "R.421-9", "La hauteur de la construction conditionne le régime.", ["hauteur_m"]);
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

		var couverture = nombre(d.hauteur_couverture_m);
		var couverte = d.couverte === true;

		if (bassin > S.BASSIN_DP) {
			return resultat("pcmi", "R.421-9", "Bassin de " + bassin + " m² : au-delà de 100 m², le permis est exigé.");
		}

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
	   Garage en chambre, combles, sous-sol, dépendance. R.421-17 g) vise la
	   transformation de plus de 5 m² de surface close et couverte non comprise
	   dans la surface de plancher. Au-delà des seuils de R.421-14, le permis
	   reprend la main : R.421-17 ne joue que si le permis n'est pas exigé. */
	function transformation(d) {
		var cree = creation(d);
		if (cree === null) {
			return resultat("confirm", "R.421-17 g)", "La surface transformée n'est pas connue.", ["sp_creee"]);
		}

		// Un changement de destination accompagné de travaux sur la structure
		// porteuse ou la façade relève du permis (R.421-14 c).
		if (d.changement_destination === true) {
			if (d.modifie_structure_ou_facade === true) {
				return resultat("pcmi", "R.421-14 c)", "Changement de destination avec modification des structures porteuses ou de la façade : permis de construire.");
			}
			if (d.modifie_structure_ou_facade !== false) {
				return resultat("confirm", "R.421-14 c)", "Un changement de destination bascule en permis s'il s'accompagne de travaux sur la structure porteuse ou la façade.", ["modifie_structure_ou_facade"]);
			}
		}

		if (cree <= S.EXISTANT_DP) {
			return resultat("confirm", "R.421-17 g)", "Transformation de " + cree + " m² : sous le seuil de 5 m², la formalité dépend des travaux extérieurs.", ["aspect_exterieur"]);
		}

		// Au-delà de 5 m², les seuils de l'existant s'appliquent : la
		// transformation crée de la surface de plancher.
		var surSeuils = surExistant(d, "Transformation");
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
			if (d.implantation === "accole") { return surExistant(d, "Garage accolé"); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Garage indépendant"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Accolé à une construction existante ou indépendant : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		abri: function (d) {
			if (d.implantation === "accole") { return surExistant(d, "Abri accolé"); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Abri indépendant"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Accolé à une construction existante ou indépendant : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		piscine: piscine,

		/* Une pergola ouverte ne crée pas de surface de plancher, seulement une
		   emprise ; adossée, elle relève de l'existant. Aucun article ne la
		   nomme : elle suit le régime commun des constructions. */
		pergola: function (d) {
			if (d.implantation === "accole") { return surExistant(d, "Pergola adossée"); }
			if (d.implantation === "independant") { return constructionNouvelle(d, "Pergola autonome"); }
			return resultat("confirm", "R.421-9 / R.421-14", "Adossée à la construction ou autonome : les règles applicables ne sont pas les mêmes.", ["implantation"]);
		},

		transformation: transformation,

		/* Modifier l'aspect extérieur d'une construction relève de la
		   déclaration préalable, sauf à toucher les structures porteuses. */
		facade: function (d) {
			if (d.modifie_structure_ou_facade === true && d.changement_destination === true) {
				return resultat("pcmi", "R.421-14 c)", "Modification de la façade accompagnant un changement de destination : permis de construire.");
			}
			return resultat("dp", "R.421-17 a)", "Modification de l'aspect extérieur d'une construction existante : déclaration préalable.");
		},

		toiture: function () {
			return resultat("dp", "R.421-17 a)", "Réfection de toiture ou création d'ouvertures modifiant l'aspect extérieur : déclaration préalable.");
		},

		solaire: function (d) {
			if (secteurBloquant(d)) {
				return resultat("confirm", "R.421-17 a)", "En secteur protégé, l'installation peut relever d'une autorisation particulière.", ["secteur_protege"]);
			}
			return resultat("dp", "R.421-17 a)", "Panneaux modifiant l'aspect extérieur d'une construction existante : déclaration préalable.");
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
