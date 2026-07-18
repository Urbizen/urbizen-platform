# -*- coding: utf-8 -*-
"""
Pièces rédigées du dossier d'urbanisme, pour deux types :
  - "dp"   : déclaration préalable (Cerfa 16702*02), pièces DP1..DP8
  - "pcmi" : permis de construire maison individuelle (Cerfa 13406*16),
             pièces PCMI1..PCMI8 (+ attestations)

Produit la notice descriptive (PCMI4 pour un PC), le bordereau des pièces,
et assemble le tout. Autonome (reportlab + pypdf). Police Unicode embarquée
dans ./assets pour un rendu correct des accents et du « m² ».
"""
import os
from io import BytesIO
from datetime import date

from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable
)
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from pypdf import PdfReader, PdfWriter

# --- Police : DejaVu (Unicode) si présente, sinon Helvetica en repli ---
_ASSETS = os.path.join(os.path.dirname(os.path.abspath(__file__)), "assets")
FONT, FONT_B, FONT_MONO = "Helvetica", "Helvetica-Bold", "Courier"
try:
    pdfmetrics.registerFont(TTFont("DejaVu", os.path.join(_ASSETS, "DejaVuSans.ttf")))
    pdfmetrics.registerFont(TTFont("DejaVu-Bold", os.path.join(_ASSETS, "DejaVuSans-Bold.ttf")))
    FONT, FONT_B = "DejaVu", "DejaVu-Bold"
except Exception:
    pass

INK = colors.HexColor("#14233B")
SOFT = colors.HexColor("#566172")
CADASTRE = colors.HexColor("#128A5A")
LINE = colors.HexColor("#C9D3DD")

PIECES_DP = [
    ("DP1", "Plan de situation du terrain"),
    ("DP2", "Plan de masse des constructions"),
    ("DP3", "Plan en coupe du terrain et de la construction"),
    ("DP4", "Plan des façades et des toitures"),
    ("DP5", "Représentation de l’aspect extérieur (le cas échéant)"),
    ("DP6", "Document graphique d’insertion (photomontage)"),
    ("DP7", "Photographie situant le terrain dans l’environnement proche"),
    ("DP8", "Photographie situant le terrain dans le paysage lointain"),
]
PIECES_PCMI = [
    ("PCMI1", "Plan de situation du terrain"),
    ("PCMI2", "Plan de masse des constructions (coté dans les 3 dimensions)"),
    ("PCMI3", "Plan en coupe du terrain et de la construction"),
    ("PCMI4", "Notice décrivant le terrain et présentant le projet"),
    ("PCMI5", "Plan des façades et des toitures"),
    ("PCMI6", "Document graphique d’insertion (3D / perspective)"),
    ("PCMI7", "Photographie situant le terrain dans l’environnement proche"),
    ("PCMI8", "Photographie situant le terrain dans le paysage lointain"),
    ("PCMI14-1", "Attestation de prise en compte de la RE2020 (construction neuve)"),
    ("PCMI-SOL", "Étude géotechnique (zone d’aléa retrait-gonflement des argiles)"),
]

CERFA_REF = {"dp": "CERFA 16702*02", "pcmi": "CERFA 13406*16"}
DOSSIER_LABEL = {
    "dp": "DÉCLARATION PRÉALABLE",
    "pcmi": "PERMIS DE CONSTRUIRE · MAISON INDIVIDUELLE",
}


def _styles():
    ss = getSampleStyleSheet()
    ss.add(ParagraphStyle("DPTitle", parent=ss["Title"], fontName=FONT_B,
                          fontSize=17, textColor=INK, spaceAfter=2, alignment=TA_LEFT))
    ss.add(ParagraphStyle("DPKicker", parent=ss["Normal"], fontName=FONT_MONO,
                          fontSize=8, textColor=CADASTRE, spaceAfter=10, leading=10))
    ss.add(ParagraphStyle("DPH2", parent=ss["Heading2"], fontName=FONT_B,
                          fontSize=11.5, textColor=INK, spaceBefore=14, spaceAfter=5))
    ss.add(ParagraphStyle("DPBody", parent=ss["Normal"], fontName=FONT,
                          fontSize=10, textColor=INK, leading=15, spaceAfter=4))
    ss.add(ParagraphStyle("DPMeta", parent=ss["Normal"], fontName=FONT,
                          fontSize=9, textColor=SOFT, leading=13))
    return ss


def _g(data, key, default="—"):
    v = str(data.get(key, "") or "").strip()
    return v if v else default


def _rule():
    return HRFlowable(width="100%", thickness=0.7, color=LINE, spaceBefore=4, spaceAfter=8)


def _who(data):
    if data.get("declarant_type") == "personne_morale":
        w = _g(data, "denomination")
        if data.get("representant"):
            w += " (représentée par %s)" % data["representant"]
        return w
    return ("%s %s" % (_g(data, "prenom", ""), _g(data, "nom", ""))).strip() or "—"


def build_notice(data: dict, dossier_type: str = "dp") -> bytes:
    is_pc = dossier_type == "pcmi"
    ss = _styles()
    buf = BytesIO()
    doc = SimpleDocTemplate(buf, pagesize=A4, leftMargin=22*mm, rightMargin=22*mm,
                            topMargin=20*mm, bottomMargin=18*mm, title="Notice descriptive")
    F = []
    F.append(Paragraph("%s · %s" % (DOSSIER_LABEL[dossier_type], CERFA_REF[dossier_type]), ss["DPKicker"]))
    F.append(Paragraph("Notice descriptive du projet" + (" (PCMI4)" if is_pc else ""), ss["DPTitle"]))
    F.append(_rule())

    F.append(Paragraph("Demandeur", ss["DPH2"]))
    F.append(Paragraph("%s, en qualité de %s." % (_who(data), _g(data, "qualite")), ss["DPBody"]))

    F.append(Paragraph("Terrain d’assiette", ss["DPH2"]))
    F.append(Paragraph(
        "Le projet est situé %s, %s %s. Références cadastrales : section %s, parcelle n°%s. "
        "Superficie du terrain : %s m²." % (
            _g(data, "terrain_adresse"), _g(data, "terrain_cp"), _g(data, "terrain_ville"),
            _g(data, "cad_section"), _g(data, "cad_numero"), _g(data, "terrain_superficie")),
        ss["DPBody"]))
    if is_pc and data.get("terrain_etat"):
        F.append(Paragraph("État actuel : %s" % data["terrain_etat"], ss["DPBody"]))

    F.append(Paragraph("Le projet", ss["DPH2"]))
    intervention = "construction nouvelle" if data.get("intervention") == "nouvelle" else "travaux sur construction existante"
    F.append(Paragraph("Nature : %s (%s). %s" % (
        _g(data, "nature"), intervention, _g(data, "description", "")), ss["DPBody"]))
    if is_pc and data.get("nb_logements"):
        F.append(Paragraph("Nombre de logements créés : %s." % data["nb_logements"], ss["DPBody"]))
    if data.get("materiaux"):
        F.append(Paragraph("Aspect extérieur — matériaux et teintes : %s." % data["materiaux"], ss["DPBody"]))
    if is_pc and data.get("insertion"):
        F.append(Paragraph("Insertion et parti architectural : %s" % data["insertion"], ss["DPBody"]))

    F.append(Paragraph("Surfaces", ss["DPH2"]))
    rows = [
        ["", "Surface de plancher", "Emprise au sol"],
        ["Existant", _g(data, "sp_existante", "0") + " m²", _g(data, "emprise_avant", "0") + " m²"],
        ["Créé", _g(data, "sp_creee", "0") + " m²", _g(data, "emprise_creee", "0") + " m²"],
        ["Total après travaux", _g(data, "sp_totale", "0") + " m²", "—"],
    ]
    t = Table(rows, colWidths=[45*mm, 55*mm, 55*mm])
    t.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, -1), FONT, 9),
        ("FONT", (0, 0), (-1, 0), FONT_B, 9),
        ("FONT", (0, 1), (0, -1), FONT_B, 9),
        ("TEXTCOLOR", (0, 0), (-1, -1), INK),
        ("LINEBELOW", (0, 0), (-1, 0), 0.8, INK),
        ("LINEBELOW", (0, 1), (-1, -2), 0.4, LINE),
        ("TOPPADDING", (0, 0), (-1, -1), 5), ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ("ALIGN", (1, 0), (-1, -1), "RIGHT"),
    ]))
    F.append(t)

    if is_pc:
        rac = []
        if data.get("raccord_eau"): rac.append("eau potable : %s" % data["raccord_eau"])
        if data.get("raccord_assainissement"): rac.append("assainissement : %s" % data["raccord_assainissement"])
        if data.get("raccord_elec"): rac.append("électricité : %s" % data["raccord_elec"])
        if rac:
            F.append(Paragraph("Raccordements et réseaux", ss["DPH2"]))
            F.append(Paragraph(" ; ".join(rac).capitalize() + ".", ss["DPBody"]))
        if data.get("architecte_nom"):
            F.append(Paragraph("Maîtrise d’œuvre", ss["DPH2"]))
            arch = "Projet établi avec le concours de l’architecte %s" % data["architecte_nom"]
            if data.get("architecte_ordre"):
                arch += " (n° Ordre %s)" % data["architecte_ordre"]
            F.append(Paragraph(arch + ".", ss["DPBody"]))

    extras = []
    if data.get("abf") == "oui":
        extras.append("Terrain en secteur protégé (avis de l’Architecte des Bâtiments de France requis).")
    if data.get("demolition") == "oui":
        extras.append("Le projet comporte une démolition.")
    if data.get("remarques"):
        extras.append(data["remarques"])
    if extras:
        F.append(Paragraph("Contexte et précisions", ss["DPH2"]))
        for e in extras:
            F.append(Paragraph(e, ss["DPBody"]))

    F.append(Spacer(1, 14)); F.append(_rule())
    F.append(Paragraph("Notice établie le %s." % date.today().strftime("%d/%m/%Y"), ss["DPMeta"]))
    doc.build(F)
    return buf.getvalue()


def build_bordereau(data: dict, pieces_fournies: list, dossier_type: str = "dp") -> bytes:
    catalogue = PIECES_PCMI if dossier_type == "pcmi" else PIECES_DP
    ss = _styles()
    buf = BytesIO()
    doc = SimpleDocTemplate(buf, pagesize=A4, leftMargin=22*mm, rightMargin=22*mm,
                            topMargin=20*mm, bottomMargin=18*mm, title="Bordereau des pièces")
    F = []
    F.append(Paragraph("%s · %s" % (DOSSIER_LABEL[dossier_type], CERFA_REF[dossier_type]), ss["DPKicker"]))
    F.append(Paragraph("Bordereau des pièces jointes", ss["DPTitle"]))
    F.append(_rule())
    F.append(Paragraph("Demandeur : %s — Terrain : %s, %s." % (
        _who(data), _g(data, "terrain_adresse"), _g(data, "terrain_ville")), ss["DPMeta"]))
    F.append(Spacer(1, 12))

    rows = [["Code", "Pièce", "Jointe"]]
    for code, label in catalogue:
        rows.append([code, label, "☑" if code in pieces_fournies else "☐"])
    t = Table(rows, colWidths=[24*mm, 112*mm, 18*mm])
    t.setStyle(TableStyle([
        ("FONT", (0, 0), (-1, 0), FONT_B, 9.5),
        ("FONT", (0, 1), (-1, -1), FONT, 9.5),
        ("FONT", (0, 1), (0, -1), FONT_MONO, 9),
        ("TEXTCOLOR", (0, 1), (0, -1), CADASTRE),
        ("LINEBELOW", (0, 0), (-1, 0), 0.8, INK),
        ("LINEBELOW", (0, 1), (-1, -1), 0.4, LINE),
        ("ALIGN", (2, 0), (2, -1), "CENTER"),
        ("TOPPADDING", (0, 0), (-1, -1), 6), ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    F.append(t)
    F.append(Spacer(1, 10))
    F.append(Paragraph("Les pièces non cochées restent à fournir avant dépôt.", ss["DPMeta"]))
    doc.build(F)
    return buf.getvalue()


def assemble_dossier(pdf_parts: list) -> bytes:
    writer = PdfWriter()
    for part in pdf_parts:
        if not part:
            continue
        for page in PdfReader(BytesIO(part)).pages:
            writer.add_page(page)
    out = BytesIO()
    writer.write(out)
    return out.getvalue()


if __name__ == "__main__":
    sample = {
        "declarant_type": "particulier", "nom": "Moreau", "prenom": "Julien",
        "qualite": "Propriétaire",
        "terrain_adresse": "7 chemin des Vignes", "terrain_cp": "36200",
        "terrain_ville": "Argenton-sur-Creuse", "cad_section": "ZC", "cad_numero": "88",
        "terrain_superficie": "1100", "terrain_etat": "terrain nu en pente douce, exposé sud.",
        "nature": "Construction d’une maison individuelle", "intervention": "nouvelle",
        "description": "Maison de plain-pied de 132 m² SP, toiture deux pans.",
        "insertion": "volumétrie basse, enduit clair, insertion dans le coteau boisé.",
        "nb_logements": "1", "materiaux": "Enduit ton sable, tuiles rouges",
        "sp_existante": "0", "sp_creee": "132", "sp_totale": "132",
        "emprise_avant": "0", "emprise_creee": "145",
        "raccord_eau": "Réseau public", "raccord_assainissement": "Individuel (ANC)",
        "raccord_elec": "Réseau public", "architecte_nom": "", "abf": "non",
    }
    n = build_notice(sample, "pcmi")
    b = build_bordereau(sample, ["PCMI1", "PCMI2", "PCMI4", "PCMI5"], "pcmi")
    d = assemble_dossier([n, b])
    open("_pc_notice.pdf", "wb").write(n); open("_pc_dossier.pdf", "wb").write(d)
    print("OK PC — notice %d o · bordereau %d o · dossier %d o" % (len(n), len(b), len(d)))
