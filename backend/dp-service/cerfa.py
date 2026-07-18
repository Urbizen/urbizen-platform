# -*- coding: utf-8 -*-
"""
Remplissage des Cerfa officiels d'urbanisme (PDF AcroForm) :
  - "dp"   : 16702*02  (déclaration préalable, maison individuelle)
  - "pcmi" : 13406*16  (permis de construire, maison individuelle)

Les champs de ces PDF portent des noms internes non devinables : l'inspecteur
ci-dessous les liste, puis vous complétez le MAPPING correspondant.

  ÉTAPE 1 (par formulaire) :
      python cerfa.py inspect cerfa_16702-02.pdf
      python cerfa.py inspect cerfa_13406-16.pdf
  → écrit champs_<pdf>.txt : tous les champs (nom, type, états possibles).

  ÉTAPE 2 : complétez MAPPING_DP / MAPPING_PCMI (et les CHECKBOXES) plus bas.

  ÉTAPE 3 : le service appelle fill_cerfa(data, pdf, mapping, checkboxes).
"""
import os
import sys
from io import BytesIO
from pypdf import PdfReader, PdfWriter


# =============================================================================
#  MAPPING — clé du formulaire  ->  nom du champ dans le Cerfa
#  Remplacez les "TODO_..." par les vrais noms lus à l'inspection.
#  Une clé restée en TODO_ est simplement ignorée (le reste se remplit).
# =============================================================================
MAPPING_DP = {
    "nom": "TODO_nom", "prenom": "TODO_prenom",
    "denomination": "TODO_denomination", "siret": "TODO_siret", "representant": "TODO_representant",
    "email": "TODO_courriel", "telephone": "TODO_tel",
    "adresse_declarant": "TODO_adr_decl", "cp_declarant": "TODO_cp_decl", "ville_declarant": "TODO_ville_decl",
    "terrain_adresse": "TODO_terrain_voie", "terrain_cp": "TODO_terrain_cp", "terrain_ville": "TODO_terrain_commune",
    "cad_section": "TODO_cad_section", "cad_numero": "TODO_cad_numero", "terrain_superficie": "TODO_terrain_sup",
    "description": "TODO_description", "materiaux": "TODO_materiaux",
    "sp_existante": "TODO_sp_exist", "sp_creee": "TODO_sp_cree", "sp_totale": "TODO_sp_total",
    "emprise_avant": "TODO_emprise_av", "emprise_creee": "TODO_emprise_cree", "surface_taxable": "TODO_surf_tax",
}
CHECKBOXES_DP = {
    # "abf_oui": ("TODO_champ_abf", "Oui"),
}

MAPPING_PCMI = {
    "nom": "TODO_nom", "prenom": "TODO_prenom",
    "denomination": "TODO_denomination", "siret": "TODO_siret", "representant": "TODO_representant",
    "email": "TODO_courriel", "telephone": "TODO_tel",
    "adresse_declarant": "TODO_adr_decl", "cp_declarant": "TODO_cp_decl", "ville_declarant": "TODO_ville_decl",
    "terrain_adresse": "TODO_terrain_voie", "terrain_cp": "TODO_terrain_cp", "terrain_ville": "TODO_terrain_commune",
    "cad_section": "TODO_cad_section", "cad_numero": "TODO_cad_numero", "terrain_superficie": "TODO_terrain_sup",
    "description": "TODO_description", "materiaux": "TODO_materiaux",
    "nb_logements": "TODO_nb_logements",
    "sp_existante": "TODO_sp_exist", "sp_creee": "TODO_sp_cree", "sp_totale": "TODO_sp_total",
    "emprise_avant": "TODO_emprise_av", "emprise_creee": "TODO_emprise_cree",
    # Déclaration fiscale (taxe d'aménagement)
    "surface_taxable": "TODO_surf_tax", "nb_stationnement": "TODO_nb_station", "piscine_m2": "TODO_piscine",
    # Architecte
    "architecte_nom": "TODO_archi_nom", "architecte_ordre": "TODO_archi_ordre",
}
CHECKBOXES_PCMI = {
    # "raccord_assainissement_anc": ("TODO_champ_anc", "Oui"),
    # "abf_oui": ("TODO_champ_abf", "Oui"),
}

# Profil par type de dossier : (nom de fichier Cerfa par défaut, mapping, checkboxes)
PROFILES = {
    "dp":   ("cerfa_16702-02.pdf", MAPPING_DP,   CHECKBOXES_DP),
    "pcmi": ("cerfa_13406-16.pdf", MAPPING_PCMI, CHECKBOXES_PCMI),
}


def inspect(pdf_path: str, out_path: str = None) -> None:
    reader = PdfReader(pdf_path)
    fields = reader.get_fields() or {}
    if out_path is None:
        base = os.path.splitext(os.path.basename(pdf_path))[0]
        out_path = "champs_%s.txt" % base
    lines = ["# Champs du Cerfa : %s" % pdf_path,
             "# %d champ(s). NOM | TYPE | ÉTATS/OPTIONS\n" % len(fields)]
    for name, f in fields.items():
        ftype = str(f.get("/FT", "")).replace("/", "")
        extra = ""
        if ftype == "Btn":
            st = f.get("/_States_", None)
            if st:
                extra = "  états=" + ",".join(str(s) for s in st)
        elif ftype == "Ch":
            op = f.get("/Opt", None)
            if op:
                extra = "  options=" + ",".join(str(o) for o in op)
        lines.append("%-46s | %-5s |%s" % (name, ftype, extra))
    with open(out_path, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))
    print("→ %d champ(s) écrits dans %s" % (len(fields), out_path))


def fill_cerfa(data: dict, pdf_path: str, mapping: dict, checkboxes: dict,
               flatten: bool = False) -> bytes:
    """Remplit le Cerfa depuis `data` selon `mapping`/`checkboxes`. Renvoie le PDF."""
    reader = PdfReader(pdf_path)
    writer = PdfWriter()
    writer.append(reader)

    values = {}
    for form_key, field in mapping.items():
        if field.startswith("TODO_"):
            continue
        val = str(data.get(form_key, "") or "").strip()
        if val:
            values[field] = val
    for form_key, (field, checked_state) in checkboxes.items():
        if data.get(form_key):
            values[field] = checked_state

    for page in writer.pages:
        writer.update_page_form_field_values(page, values)
    try:
        writer.set_need_appearances_writer(True)
    except Exception:
        pass

    out = BytesIO()
    writer.write(out)
    return out.getvalue()


if __name__ == "__main__":
    if len(sys.argv) >= 3 and sys.argv[1] == "inspect":
        inspect(sys.argv[2])
    else:
        print("Usage : python cerfa.py inspect <cerfa.pdf>")
