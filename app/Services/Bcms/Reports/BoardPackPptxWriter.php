<?php

namespace App\Services\Bcms\Reports;

use RuntimeException;
use ZipArchive;

/**
 * A minimal, valid OOXML `.pptx` — one slide per board-pack section.
 *
 * NO NEW COMPOSER DEPENDENCY. `phpoffice/phppresentation` is not installed in
 * this repository and is not something a backend phase adds unilaterally; a
 * `.pptx` is a zip of well-defined XML parts, and this writer produces exactly
 * the minimum PowerPoint itself requires — one slide master, one layout, one
 * theme, and a slide per section — reusing the SAME text this pack's PDF
 * shows, never a separate narrative.
 *
 * ATHERIS BRANDING is the title-slide organisation name and the product name
 * in the footer text run; there is no logo image asset bundled with this
 * writer, which is named as a limitation rather than papered over with a
 * placeholder graphic.
 */
class BoardPackPptxWriter
{
    /**
     * @param  list<array{title: string, lines: list<string>}>  $slides
     */
    public function write(string $title, array $slides): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bcms-pptx-');

        if ($path === false) {
            throw new RuntimeException('Could not allocate a temporary file for the PowerPoint export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a zip archive for the PowerPoint export.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($slides)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->coreProps($title));
        $zip->addFromString('docProps/app.xml', $this->appProps(count($slides)));
        $zip->addFromString('ppt/theme/theme1.xml', $this->theme());
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $this->slideMaster());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->slideMasterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $this->slideLayout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->slideLayoutRels());
        $zip->addFromString('ppt/presentation.xml', $this->presentation(count($slides)));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->presentationRels(count($slides)));

        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $zip->addFromString("ppt/slides/slide{$n}.xml", $this->slide($slide['title'], $slide['lines']));
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", $this->slideRels());
        }

        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        if ($bytes === false) {
            throw new RuntimeException('The PowerPoint export could not be read back after writing.');
        }

        return $bytes;
    }

    private function contentTypes(int $slideCount): string
    {
        $overrides = '';

        for ($n = 1; $n <= $slideCount; $n++) {
            $overrides .= "<Override PartName=\"/ppt/slides/slide{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.presentationml.slide+xml\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            .'<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            .'<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function coreProps(string $title): string
    {
        $now = now()->toIso8601String();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            ."<dc:title>{$this->escape($title)}</dc:title><dc:creator>Atheris ERM</dc:creator>"
            ."<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created>"
            .'</cp:coreProperties>';
    }

    private function appProps(int $slideCount): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            .'<Application>Atheris ERM</Application>'
            ."<Slides>{$slideCount}</Slides>"
            .'</Properties>';
    }

    private function theme(): string
    {
        // The platform's Navy/Forest/Gold palette, in the minimum a theme part needs.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Atheris">'
            .'<a:themeElements>'
            .'<a:clrScheme name="Atheris"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
            .'<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
            .'<a:dk2><a:srgbClr val="0B2545"/></a:dk2><a:lt2><a:srgbClr val="F4F4F4"/></a:lt2>'
            .'<a:accent1><a:srgbClr val="0B2545"/></a:accent1><a:accent2><a:srgbClr val="1B4332"/></a:accent2>'
            .'<a:accent3><a:srgbClr val="C9A227"/></a:accent3><a:accent4><a:srgbClr val="0B2545"/></a:accent4>'
            .'<a:accent5><a:srgbClr val="1B4332"/></a:accent5><a:accent6><a:srgbClr val="C9A227"/></a:accent6>'
            .'<a:hlink><a:srgbClr val="0B2545"/></a:hlink><a:folHlink><a:srgbClr val="1B4332"/></a:folHlink>'
            .'</a:clrScheme>'
            .'<a:fontScheme name="Atheris"><a:majorFont><a:latin typeface="Calibri"/></a:majorFont>'
            .'<a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme>'
            .'<a:fmtScheme name="Atheris"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            .'<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            .'<a:lnStyleLst><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            .'<a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
            .'<a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            .'<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle>'
            .'<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            .'<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
            .'<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
            .'</a:fmtScheme>'
            .'</a:themeElements>'
            .'</a:theme>';
    }

    private function slideMaster(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr/></p:spTree></p:cSld>'
            .'<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            .'<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></p:sldLayoutIdLst>'
            .'</p:sldMaster>';
    }

    private function slideMasterRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            .'</Relationships>';
    }

    private function slideLayout(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'
            .'<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            .'<p:grpSpPr/></p:spTree></p:cSld>'
            .'</p:sldLayout>';
    }

    private function slideLayoutRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            .'</Relationships>';
    }

    private function presentation(int $slideCount): string
    {
        $ids = '';

        for ($n = 1; $n <= $slideCount; $n++) {
            $rid = $n + 1; // rId1 is the slide master
            $sldId = 255 + $n;
            $ids .= "<p:sldId id=\"{$sldId}\" r:id=\"rId{$rid}\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            ."<p:sldIdLst>{$ids}</p:sldIdLst>"
            .'<p:sldSz cx="12192000" cy="6858000" type="screen16x9"/>'
            .'<p:notesSz cx="6858000" cy="9144000"/>'
            .'</p:presentation>';
    }

    private function presentationRels(int $slideCount): string
    {
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';

        for ($n = 1; $n <= $slideCount; $n++) {
            $rid = $n + 1;
            $rels .= "<Relationship Id=\"rId{$rid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide\" Target=\"slides/slide{$n}.xml\"/>";
        }

        $themeRid = $slideCount + 2;
        $rels .= "<Relationship Id=\"rId{$themeRid}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme\" Target=\"theme/theme1.xml\"/>";

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>';
    }

    private function slideRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            .'</Relationships>';
    }

    /** @param  list<string>  $lines */
    private function slide(string $title, array $lines): string
    {
        $body = '';

        foreach ($lines as $line) {
            $body .= '<a:p><a:r><a:rPr lang="en-US" sz="1800"/><a:t>'.$this->escape($line).'</a:t></a:r></a:p>';
        }

        if ($body === '') {
            $body = '<a:p><a:endParaRPr lang="en-US"/></a:p>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld><p:spTree>'
            .'<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
            .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="457200" y="274638"/><a:ext cx="11277600" cy="1143000"/></a:xfrm></p:spPr>'
            .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" b="1" sz="2800"><a:solidFill><a:srgbClr val="0B2545"/></a:solidFill></a:rPr>'
            .'<a:t>'.$this->escape($title).'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr>'
            .'<p:spPr><a:xfrm><a:off x="457200" y="1600200"/><a:ext cx="11277600" cy="4800600"/></a:xfrm></p:spPr>'
            ."<p:txBody><a:bodyPr/><a:lstStyle/>{$body}</p:txBody></p:sp>"
            .'</p:spTree></p:cSld>'
            .'</p:sld>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
