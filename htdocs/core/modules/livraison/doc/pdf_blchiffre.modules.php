<?php
/* Copyright (C) 2004-2014 Laurent Destailleur   <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2014 Regis Houssin         <regis.houssin@capnetworks.com>
 * Copyright (C) 2007      Franky Van Liedekerke <franky.van.liedekerke@telenet.be>
 * Copyright (C) 2008      Chiptronik
 * Copyright (C) 2011-2012 Philippe Grand        <philippe.grand@atoo-net.com>
 * Copyright (C) 2015      Marcos García         <marcosgdf@gmail.com>

 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/livraison/doc/pdf_blchiffre.modules.php
 *	\ingroup    livraison
 *	\brief      File of class to manage receving receipts with template blchiffre
 *	\author	    Laurent Destailleur
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/livraison/modules_livraison.php';
require_once DOL_DOCUMENT_ROOT.'/livraison/class/livraison.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';


/**
 *	Classe permettant de generer les bons de livraison au modele Typho
 */
class pdf_blchiffre extends ModelePDFDeliveryOrder
{
    var $db;
    var $name;
    var $description;
    var $type;

    var $phpmin = array(4,3,0); // Minimum version of PHP required by module
    var $version = 'dolibarr';

    var $page_largeur;
    var $page_hauteur;
    var $format;
	var $marge_gauche;
	var	$marge_droite;
	var	$marge_haute;
	var	$marge_basse;

	var $emetteur;	// Objet societe qui emet

	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	function __construct($db)
	{
		global $conf,$langs,$mysoc;

		$langs->load("main");
		$langs->load("bills");
		$langs->load("sendings");
		$langs->load("companies");

		$this->db = $db;
		$this->name = "blchiffre";
		$this->description = $langs->trans("DocumentModelBlchiffre");

		// Dimension page pour format A4
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur,$this->page_hauteur);
		$this->marge_gauche=isset($conf->global->MAIN_PDF_MARGIN_LEFT)?$conf->global->MAIN_PDF_MARGIN_LEFT:10;
		$this->marge_droite=isset($conf->global->MAIN_PDF_MARGIN_RIGHT)?$conf->global->MAIN_PDF_MARGIN_RIGHT:10;
		$this->marge_haute =isset($conf->global->MAIN_PDF_MARGIN_TOP)?$conf->global->MAIN_PDF_MARGIN_TOP:10;
		$this->marge_basse =isset($conf->global->MAIN_PDF_MARGIN_BOTTOM)?$conf->global->MAIN_PDF_MARGIN_BOTTOM:10;

		
		$this->option_logo = 1;                    // Affiche logo
		$this->option_tva = 1;                     // Gere option tva FACTURE_TVAOPTION
		$this->option_modereg = 1;                 // Affiche mode reglement
		$this->option_condreg = 1;                 // Affiche conditions reglement
		$this->option_codeproduitservice = 1;      // Affiche code produit-service
		$this->option_multilang = 1;               // Dispo en plusieurs langues
		$this->option_escompte = 0;                // Affiche si il y a eu escompte
		$this->option_credit_note = 0;             // Support credit notes
		$this->option_freetext = 1;				   // Support add of a personalised text
		$this->option_draft_watermark = 1;		   // Support add of a watermark on drafts

		$this->franchise=!$mysoc->tva_assuj;

		// Get source company
		$this->emetteur=$mysoc;
		if (empty($this->emetteur->country_code)) $this->emetteur->country_code=substr($langs->defaultlang,-2);    // By default, if was not defined

		// Define position of columns
		$this->posxdesc=$this->marge_gauche+1;
		if($conf->global->PRODUCT_USE_UNITS)
		{
			$this->posxtva=99;
			$this->posxup=114;
			$this->posxqty=130;
			$this->posxunit=147;
		}
		else
		{
			$this->posxtva=112;
			$this->posxup=126;
			$this->posxqty=145;
		}
		$this->posxdiscount=162;
		$this->postotalht=174;
		if (! empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT)) $this->posxtva=$this->posxup;
		$this->posxpicture=$this->posxtva - (empty($conf->global->MAIN_DOCUMENTS_WITH_PICTURE_WIDTH)?20:$conf->global->MAIN_DOCUMENTS_WITH_PICTURE_WIDTH);	// width of images
		if ($this->page_largeur < 210) // To work with US executive format
		{
			$this->posxpicture-=20;
			$this->posxtva-=20;
			$this->posxup-=20;
			$this->posxqty-=20;
			$this->posxunit-=20;
			$this->posxdiscount-=20;
			$this->postotalht-=20;
		}

		$this->tva=array();
		$this->localtax1=array();
		$this->localtax2=array();
		$this->atleastoneratenotnull=0;
		$this->atleastonediscount=0;
	}


	/**
     *  Function to build pdf onto disk
     *
     *  @param		Object		$object				Object to generate
     *  @param		Translate	$outputlangs		Lang output object
     *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
     *  @param		int			$hidedetails		Do not show line details
     *  @param		int			$hidedesc			Do not show desc
     *  @param		int			$hideref			Do not show ref
     *  @return     int             			1=OK, 0=KO
	 */
	function write_file($object,$outputlangs,$srctemplatepath='',$hidedetails=0,$hidedesc=0,$hideref=0)
	{
		global $user,$langs,$conf,$mysoc,$hookmanager;

		if (! is_object($outputlangs)) $outputlangs=$langs;
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (! empty($conf->global->MAIN_USE_FPDF)) $outputlangs->charset_output='ISO-8859-1';

		$outputlangs->load("main");
		$outputlangs->load("dict");
		$outputlangs->load("companies");
		$outputlangs->load("bills");
		$outputlangs->load("products");
		$outputlangs->load("deliveries");
		$outputlangs->load("sendings");

		if ($conf->expedition->dir_output)
		{
			$object->fetch_thirdparty();

			// Definition of $dir and $file
			if ($object->specimen)
			{
				$dir = $conf->expedition->dir_output."/receipt";
				$file = $dir . "/SPECIMEN.pdf";
			}
			else
			{
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = $conf->expedition->dir_output."/receipt/" . $objectref;
				$file = $dir . "/" . $objectref . ".pdf";
			}

			if (! file_exists($dir))
			{
				if (dol_mkdir($dir) < 0)
				{
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir",$dir);
					return 0;
				}
			}

			if (file_exists($dir))
			{
				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('beforePDFCreation',$parameters,$object,$action);    // Note that $action and $object may have been modified by some hooks

				$nblines = count($object->lines);


				// Create pdf instance
				$pdf=pdf_getInstance($this->format);
                $default_font_size = pdf_getPDFFontSize($outputlangs);	// Must be after pdf_getInstance
                $heightforinfotot = 30;	// Height reserved to output the info and total part
		        $heightforfreetext= (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT)?$conf->global->MAIN_PDF_FREETEXT_HEIGHT:5);	// Height reserved to output the free text on last page
	            $heightforfooter = $this->marge_basse + 8;	// Height reserved to output the footer (value include bottom margin)
                $pdf->SetAutoPageBreak(1,0);

                if (class_exists('TCPDF'))
                {
                    $pdf->setPrintHeader(false);
                    $pdf->setPrintFooter(false);
                }
                $pdf->SetFont(pdf_getPDFFont($outputlangs));
                // Set path to the background PDF File
                if (empty($conf->global->MAIN_DISABLE_FPDI) && ! empty($conf->global->MAIN_ADD_PDF_BACKGROUND))
                {
                    $pagecount = $pdf->setSourceFile($conf->mycompany->dir_output.'/'.$conf->global->MAIN_ADD_PDF_BACKGROUND);
                    $tplidx = $pdf->importPage(1);
                }

				// We get the shipment that is the origin of delivery receipt
				$expedition=new Expedition($this->db);
				$result = $expedition->fetch($object->origin_id);
				// Now we get the order that is origin of shipment
				$commande = new Commande($this->db);
				if ($expedition->origin == 'commande')
				{
					$commande->fetch($expedition->origin_id);
				}
				$object->commande=$commande;	// We set order of shipment onto delivery.
				$object->commande->loadExpeditions();
				//$object->lines = $expedition->lines; //fk_origin_line
				
				//var_dump($object->lines);
				// Récupération des infos
				$Tcorrespondance = array();
				foreach ($object->commande->lines as $coLinesKey => &$coLines)
				{
				    $Tcorrespondance[$coLines->id] = $coLinesKey;
				}
				foreach ($object->lines as &$line)
				{
				    if(!empty($Tcorrespondance[$line->fk_origin_line]))
				    {
				        $coLine = $object->commande->lines[$Tcorrespondance[$line->fk_origin_line]];
				        $coLine->qty = $line->qty_shipped;
				        $line = $coLine;
				        
				        //var_dump($line);
				        $coLine->total_ht = $coLine->qty * $coLine->price;
				        $coLine->total_ttc = $coLine->total_ht * ( 1 + $coLine->total_tva / 100 );
				        $coLine->total_tva = $coLine->total_ttc - $coLine->total_ht;
				        
				     
				    }
				}
				/*
                    public 'element' => string 'expeditionlignebatch' (length=20)
                    public 'sellby' => string '' (length=0)
                    public 'eatby' => string '' (length=0)
                    public 'batch' => string 'REEXISORS6200650040_CF1711-041' (length=30)
                    public 'dluo_qty' => string '2' (length=1)
                    public 'entrepot_id' => null
                    public 'fk_origin_stock' => string '100' (length=3)
                    public 'fk_expeditiondet' => string '1211' (length=4)
				 */
				
				$nblignes = count($object->lines);

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128,128,128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("DeliveryOrder"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("DeliveryOrder"));
				if (! empty($conf->global->MAIN_DISABLE_PDF_COMPRESSION)) $pdf->SetCompression(false);

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right

				/*
				// Positionne $this->atleastonediscount si on a au moins une remise
				for ($i = 0 ; $i < $nblines ; $i++)
				{
				 	if ($object->lines[$i]->remise_percent)
				 	{
				 		$this->atleastonediscount++;
				 	}
				}
 				if (empty($this->atleastonediscount))
				{
					$this->posxpicture+=($this->postotalht - $this->posxdiscount);
					$this->posxtva+=($this->postotalht - $this->posxdiscount);
					$this->posxup+=($this->postotalht - $this->posxdiscount);
					$this->posxqty+=($this->postotalht - $this->posxdiscount);
					$this->posxdiscount+=($this->postotalht - $this->posxdiscount);
					//$this->postotalht;
				}
				*/

				// New page
				$pdf->AddPage();
				if (! empty($tplidx)) $pdf->useTemplate($tplidx);
				$pagenb++;
				$this->_pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('','', $default_font_size - 1);
				$pdf->MultiCell(0, 3, '');		// Set interline to 3
				$pdf->SetTextColor(0,0,0);

				$tab_top = 90;
				$tab_top_newpage = (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)?42:10);
				$tab_height = 130;
				$tab_height_newpage = 150;

				// Incoterm
				$height_incoterms = 0;
				if ($conf->incoterm->enabled)
				{
					$desc_incoterms = $object->getIncotermsForPDF();
					if ($desc_incoterms)
					{
						$tab_top = 88;

						$pdf->SetFont('','', $default_font_size - 1);
						$pdf->writeHTMLCell(190, 3, $this->posxdesc-1, $tab_top-1, dol_htmlentitiesbr($desc_incoterms), 0, 1);
						$nexY = $pdf->GetY();
						$height_incoterms=$nexY-$tab_top;
	
						// Rect prend une longueur en 3eme param
						$pdf->SetDrawColor(192,192,192);
						$pdf->Rect($this->marge_gauche, $tab_top-1, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_incoterms+1);
	
						$tab_top = $nexY+6;
						$height_incoterms += 4;
					}
				}

				// Affiche notes
				if (! empty($object->note_public))
				{
					$tab_top = 88 + $height_incoterms;

					$pdf->SetFont('','', $default_font_size - 1);
					$pdf->writeHTMLCell(190, 3, $this->posxdesc-1, $tab_top, dol_htmlentitiesbr($object->note_public), 0, 1);
					$nexY = $pdf->GetY();
					$height_note=$nexY-$tab_top;

					// Rect prend une longueur en 3eme param
					$pdf->SetDrawColor(192,192,192);
					$pdf->Rect($this->marge_gauche, $tab_top-1, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_note+1);

					$tab_height = $tab_height - $height_note;
					$tab_top = $nexY+6;
				}
				else
				{
					$height_note=0;
				}

				$iniY = $tab_top + 7;
				$curY = $tab_top + 7;
				$nexY = $tab_top + 7;

				// Loop on each lines
				for ($i = 0 ; $i < $nblignes ; $i++)
				{
					$curY = $nexY;
					$pdf->SetFont('','', $default_font_size - 1);   // Into loop to work with multipage
					$pdf->SetTextColor(0,0,0);

					$pdf->setTopMargin($tab_top_newpage);
					$pdf->setPageOrientation('', 1, $heightforfooter+$heightforfreetext+$heightforinfotot);	// The only function to edit the bottom margin of current page to set it.
					$pageposbefore=$pdf->getPage();

					// Description of product line
					$curX = $this->posxdesc-1;

					$showpricebeforepagebreak=1;

					$pdf->startTransaction();
					pdf_writelinedesc($pdf,$object,$i,$outputlangs,$this->posxtva-$curX,3,$curX,$curY,$hideref,$hidedesc);
					$pageposafter=$pdf->getPage();
					if ($pageposafter > $pageposbefore)	// There is a pagebreak
					{
						$pdf->rollbackTransaction(true);
						$pageposafter=$pageposbefore;
						//print $pageposafter.'-'.$pageposbefore;exit;
						$pdf->setPageOrientation('', 1, $heightforfooter);	// The only function to edit the bottom margin of current page to set it.
						pdf_writelinedesc($pdf,$object,$i,$outputlangs,$this->posxtva-$curX,4,$curX,$curY,$hideref,$hidedesc);
						$pageposafter=$pdf->getPage();
						$posyafter=$pdf->GetY();
						if ($posyafter > ($this->page_hauteur - ($heightforfooter+$heightforfreetext+$heightforinfotot)))	// There is no space left for total+free text
						{
							if ($i == ($nblignes-1))	// No more lines, and no space left to show total, so we create a new page
							{
								$pdf->AddPage('','',true);
								if (! empty($tplidx)) $pdf->useTemplate($tplidx);
								if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs);
								$pdf->setPage($pageposafter+1);
							}
						}
						else
						{
							// We found a page break
							$showpricebeforepagebreak=0;
						}
					}
					else	// No pagebreak
					{
						$pdf->commitTransaction();
					}

					$nexY = $pdf->GetY();
					$pageposafter=$pdf->getPage();
					$pdf->setPage($pageposbefore);
					$pdf->setTopMargin($this->marge_haute);
					$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.

					// We suppose that a too long description is moved completely on next page
					if ($pageposafter > $pageposbefore && empty($showpricebeforepagebreak)) {
						$pdf->setPage($pageposafter); $curY = $tab_top_newpage;
					}

					$pdf->SetFont('','',  $default_font_size - 1);   // On repositionne la police par defaut

					// VAT Rate
					if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT) && empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_COLUMN))
					{
						$vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails);
						$pdf->SetXY($this->posxtva, $curY);
						$pdf->MultiCell($this->posxup-$this->posxtva-0.8, 3, $vat_rate, 0, 'R');
					}

					// Unit price before discount
					$up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails);
					$pdf->SetXY($this->posxup, $curY);
					$pdf->MultiCell($this->posxqty-$this->posxup-0.8, 3, $up_excl_tax, 0, 'R', 0);

					// Quantity
					$qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails);
					$pdf->SetXY($this->posxqty, $curY);
					// Enough for 6 chars
					if($conf->global->PRODUCT_USE_UNITS)
					{
						$pdf->MultiCell($this->posxunit-$this->posxqty-0.8, 4, $qty, 0, 'R');
					}
					else
					{
						$pdf->MultiCell($this->posxdiscount-$this->posxqty-0.8, 4, $qty, 0, 'R');
					}

					// Unit
					if($conf->global->PRODUCT_USE_UNITS)
					{
						$unit = pdf_getlineunit($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$pdf->SetXY($this->posxunit, $curY);
						$pdf->MultiCell($this->posxdiscount-$this->posxunit-0.8, 4, $unit, 0, 'L');
					}

					// Discount on line
					$pdf->SetXY($this->posxdiscount, $curY);
					if ($object->lines[$i]->remise_percent)
					{
						$pdf->SetXY($this->posxdiscount-2, $curY);
						$remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails);
						$pdf->MultiCell($this->postotalht-$this->posxdiscount+2, 3, $remise_percent, 0, 'R');
					}

					// Total HT line
					$total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails);
					$pdf->SetXY($this->postotalht, $curY);
					$pdf->MultiCell($this->page_largeur-$this->marge_droite-$this->postotalht, 3, $total_excl_tax, 0, 'R', 0);

					// Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
					if ($conf->multicurrency->enabled && $object->multicurrency_tx != 1) $tvaligne=$object->lines[$i]->multicurrency_total_tva;
					else $tvaligne=$object->lines[$i]->total_tva;
					
					$localtax1ligne=$object->lines[$i]->total_localtax1;
					$localtax2ligne=$object->lines[$i]->total_localtax2;
					$localtax1_rate=$object->lines[$i]->localtax1_tx;
					$localtax2_rate=$object->lines[$i]->localtax2_tx;
					$localtax1_type=$object->lines[$i]->localtax1_type;
					$localtax2_type=$object->lines[$i]->localtax2_type;

					if ($object->remise_percent) $tvaligne-=($tvaligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax1ligne-=($localtax1ligne*$object->remise_percent)/100;
					if ($object->remise_percent) $localtax2ligne-=($localtax2ligne*$object->remise_percent)/100;

					$vatrate=(string) $object->lines[$i]->tva_tx;

					// Retrieve type from database for backward compatibility with old records
					if ((! isset($localtax1_type) || $localtax1_type=='' || ! isset($localtax2_type) || $localtax2_type=='') // if tax type not defined
					&& (! empty($localtax1_rate) || ! empty($localtax2_rate))) // and there is local tax
					{
						$localtaxtmp_array=getLocalTaxesFromRate($vatrate,0,$object->thirdparty,$mysoc);
						$localtax1_type = $localtaxtmp_array[0];
						$localtax2_type = $localtaxtmp_array[2];
					}

				    // retrieve global local tax
					if ($localtax1_type && $localtax1ligne != 0)
						$this->localtax1[$localtax1_type][$localtax1_rate]+=$localtax1ligne;
					if ($localtax2_type && $localtax2ligne != 0)
						$this->localtax2[$localtax2_type][$localtax2_rate]+=$localtax2ligne;

					if (($object->lines[$i]->info_bits & 0x01) == 0x01) $vatrate.='*';
					if (! isset($this->tva[$vatrate])) 				$this->tva[$vatrate]='';
					$this->tva[$vatrate] += $tvaligne;

					// Add line
					if (! empty($conf->global->MAIN_PDF_DASH_BETWEEN_LINES) && $i < ($nblignes - 1))
					{
						$pdf->setPage($pageposafter);
						$pdf->SetLineStyle(array('dash'=>'1,1','color'=>array(80,80,80)));
						//$pdf->SetDrawColor(190,190,200);
						$pdf->line($this->marge_gauche, $nexY+1, $this->page_largeur - $this->marge_droite, $nexY+1);
						$pdf->SetLineStyle(array('dash'=>0));
					}

					$nexY+=2;    // Passe espace entre les lignes

					// Detect if some page were added automatically and output _tableau for past pages
					while ($pagenb < $pageposafter)
					{
						$pdf->setPage($pagenb);
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1, $object->multicurrency_code);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1, $object->multicurrency_code);
						}
						$this->_pagefoot($pdf,$object,$outputlangs,1);
						$pagenb++;
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, 0);	// The only function to edit the bottom margin of current page to set it.
						if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs);
					}
					if (isset($object->lines[$i+1]->pagebreak) && $object->lines[$i+1]->pagebreak)
					{
						if ($pagenb == 1)
						{
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, 0, 1, $object->multicurrency_code);
						}
						else
						{
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1, $object->multicurrency_code);
						}
						$this->_pagefoot($pdf,$object,$outputlangs,1);
						// New page
						$pdf->AddPage();
						if (! empty($tplidx)) $pdf->useTemplate($tplidx);
						$pagenb++;
						if (empty($conf->global->MAIN_PDF_DONOTREPEAT_HEAD)) $this->_pagehead($pdf, $object, 0, $outputlangs);
					}
				}


				// Show square
				if ($pagenb == 1)
				{
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 0, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}
				else
				{
					$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 1, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}

				// Affiche zone infos
				$this->_tableau_info($pdf, $object, $bottomlasttab, $outputlangs);

				// Pied de page
				$this->_pagefoot($pdf,$object,$outputlangs);

				if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();

				// Check product remaining to be delivered
				// TODO doit etre modifie
				//$waitingDelivery = $object->getRemainingDelivered();
				/*
				$waitingDelivery='';

				if (is_array($waitingDelivery) & !empty($waitingDelivery))
				{
					$pdf->AddPage();

					$this->_pagehead($pdf, $object, 1, $outputlangs);
					$pdf-> SetY(90);

					$w=array(40,100,50);
					$header=array($outputlangs->transnoentities('Reference'),
								  $outputlangs->transnoentities('Label'),
								  $outputlangs->transnoentities('Qty')
								  );

    				// Header
    				$num = count($header);
   					for($i = 0; $i < $num; $i++)
   					{
   						$pdf->Cell($w[$i],7,$header[$i],1,0,'C');
   					}

			    	$pdf->Ln();

			    	// Data
					foreach($waitingDelivery as $value)
					{
						$pdf->Cell($w[0], 6, $value['ref'], 1, 0, 'L');
						$pdf->Cell($w[1], 6, $value['label'], 1, 0, 'L');
						$pdf->Cell($w[2], 6, $value['qty'], 1, 1, 'R');

						if ($pdf->GetY() > 250)
						{
							$this->_pagefoot($pdf,$object,$outputlangs,1);

							$pdf->AddPage('P', 'A4');

							$pdf->SetFont('','', $default_font_size - 1);
							$this->_pagehead($pdf, $object, 0, $outputlangs);

							$pdf-> SetY(40);

							$num = count($header);
							for($i = 0; $i < $num; $i++)
							{
								$pdf->Cell($w[$i],7,$header[$i],1,0,'C');
							}

							$pdf->Ln();
						}
					}

					$this->_pagefoot($pdf,$object,$outputlangs);

					if (method_exists($pdf,'AliasNbPages')) $pdf->AliasNbPages();
				}*/

				$pdf->Close();

				$pdf->Output($file,'F');

				// Add pdfgeneration hook
				if (! is_object($hookmanager))
				{
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation',$parameters,$this,$action);    // Note that $action and $object may have been modified by some hooks

				if (! empty($conf->global->MAIN_UMASK))
					@chmod($file, octdec($conf->global->MAIN_UMASK));

				return 1;	// pas d'erreur
			}
			else
			{
				$this->error=$langs->transnoentities("ErrorCanNotCreateDir",$dir);
				return 0;
			}
		}

		$this->error=$langs->transnoentities("ErrorConstantNotDefined","LIVRAISON_OUTPUTDIR");
		return 0;
	}

	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		PDF			$pdf     		Object PDF
	 *   @param		Object		$object			Object to show
	 *   @param		int			$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @return	void
	 */
	function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
		global $conf,$mysoc;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetFont('','', $default_font_size);
		$pdf->SetXY($this->marge_gauche, $posy);

		$larg_sign = ($this->page_largeur-$this->marge_gauche-$this->marge_droite)/3;
		$pdf->Rect($this->marge_gauche, $posy + 1, $larg_sign, 25);
		$pdf->SetXY($this->marge_gauche + 2, $posy + 2);
		$pdf->MultiCell($larg_sign,2, $outputlangs->trans("For").' '.$outputlangs->convToOutputCharset($mysoc->name).":",'','L');

		$pdf->Rect(2*$larg_sign+$this->marge_gauche, $posy + 1, $larg_sign, 25);
		$pdf->SetXY(2*$larg_sign+$this->marge_gauche + 2, $posy + 2);
		$pdf->MultiCell($larg_sign,2, $outputlangs->trans("ForCustomer").':','','L');
	}

	/**
	 *   Show table for lines
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		string		$tab_top		Top position of table
	 *   @param		string		$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y (not used)
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @param		string		$currency		Currency code
	 *   @return	void
	 */
	function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop=0, $hidebottom=0, $currency='')
	{
		global $conf;

		// Force to disable hidetop and hidebottom
		$hidebottom=0;
		if ($hidetop) $hidetop=-1;

		$currency = !empty($currency) ? $currency : $conf->currency;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0,0,0);
		$pdf->SetFont('','', $default_font_size - 2);

		if (empty($hidetop))
		{
			$titre = $outputlangs->transnoentities("AmountInCurrency",$outputlangs->transnoentitiesnoconv("Currency".$currency));
			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top-4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);

			//$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (! empty($conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR)) $pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur-$this->marge_droite-$this->marge_gauche, 5, 'F', null, explode(',',$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR));
		}

		$pdf->SetDrawColor(128,128,128);
		$pdf->SetFont('','', $default_font_size - 1);

		// Output Rect
		$this->printRect($pdf,$this->marge_gauche, $tab_top, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $tab_height, $hidetop, $hidebottom);	// Rect prend une longueur en 3eme param et 4eme param

		if (empty($hidetop))
		{
			$pdf->line($this->marge_gauche, $tab_top+5, $this->page_largeur-$this->marge_droite, $tab_top+5);	// line prend une position y en 2eme param et 4eme param

			$pdf->SetXY($this->posxdesc-1, $tab_top+1);
			$pdf->MultiCell(108,2, $outputlangs->transnoentities("Designation"),'','L');
		}

		if (empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT) && empty($conf->global->MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_COLUMN))
		{
			$pdf->line($this->posxtva-1, $tab_top, $this->posxtva-1, $tab_top + $tab_height);
			if (empty($hidetop))
			{
				$pdf->SetXY($this->posxtva-3, $tab_top+1);
				$pdf->MultiCell($this->posxup-$this->posxtva+3,2, $outputlangs->transnoentities("VAT"),'','C');
			}
		}

		$pdf->line($this->posxup-1, $tab_top, $this->posxup-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			$pdf->SetXY($this->posxup-1, $tab_top+1);
			$pdf->MultiCell($this->posxqty-$this->posxup-1,2, $outputlangs->transnoentities("PriceUHT"),'','C');
		}

		$pdf->line($this->posxqty-1, $tab_top, $this->posxqty-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			$pdf->SetXY($this->posxqty-1, $tab_top+1);
			if($conf->global->PRODUCT_USE_UNITS)
			{
				$pdf->MultiCell($this->posxunit-$this->posxqty-1,2, $outputlangs->transnoentities("Qty"),'','C');
			}
			else
			{
				$pdf->MultiCell($this->posxdiscount-$this->posxqty-1,2, $outputlangs->transnoentities("Qty"),'','C');
			}
		}

		if($conf->global->PRODUCT_USE_UNITS) {
			$pdf->line($this->posxunit - 1, $tab_top, $this->posxunit - 1, $tab_top + $tab_height);
			if (empty($hidetop)) {
				$pdf->SetXY($this->posxunit - 1, $tab_top + 1);
				$pdf->MultiCell($this->posxdiscount - $this->posxunit - 1, 2, $outputlangs->transnoentities("Unit"), '',
					'C');
			}
		}

		$pdf->line($this->posxdiscount-1, $tab_top, $this->posxdiscount-1, $tab_top + $tab_height);
		if (empty($hidetop))
		{
			if ($this->atleastonediscount)
			{
				$pdf->SetXY($this->posxdiscount-1, $tab_top+1);
				$pdf->MultiCell($this->postotalht-$this->posxdiscount+1,2, $outputlangs->transnoentities("ReductionShort"),'','C');
			}
		}

		if ($this->atleastonediscount)
		{
			$pdf->line($this->postotalht, $tab_top, $this->postotalht, $tab_top + $tab_height);
		}
		if (empty($hidetop))
		{
			$pdf->SetXY($this->postotalht-1, $tab_top+1);
			$pdf->MultiCell(30,2, $outputlangs->transnoentities("TotalHT"),'','C');
		}
	}

	/**
	 *  Show top header of page.
	 *
	 *  @param	PDF			$pdf     		Object PDF
	 *  @param  Object		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @return	void
	 */
	function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		global $conf,$langs,$hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf,$outputlangs,$this->page_hauteur);

		// Show Draft Watermark
		if($object->statut==0 && (! empty($conf->global->COMMANDE_DRAFT_WATERMARK)) )
		{
			pdf_watermark($pdf,$outputlangs,$this->page_hauteur,$this->page_largeur,'mm',$conf->global->COMMANDE_DRAFT_WATERMARK);
		}

		$pdf->SetTextColor(0,0,60);
		$pdf->SetFont('','B', $default_font_size + 3);

		$posy=$this->marge_haute;
		$posx=$this->page_largeur-$this->marge_droite-100;

		$pdf->SetXY($this->marge_gauche,$posy);

		// Logo
		$logo=$conf->mycompany->dir_output.'/logos/'.$this->emetteur->logo;
		if ($this->emetteur->logo)
		{
			if (is_readable($logo))
			{
			    $height=pdf_getHeightForLogo($logo);
			    $pdf->Image($logo, $this->marge_gauche, $posy, 0, $height);	// width=0 (auto)
			}
			else
			{
				$pdf->SetTextColor(200,0,0);
				$pdf->SetFont('','B', $default_font_size - 2);
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound",$logo), 0, 'L');
				$pdf->MultiCell(100, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
			}
		}
		else $pdf->MultiCell(100, 4, $this->emetteur->name, 0, 'L');

		$pdf->SetFont('','B', $default_font_size + 2);
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		$pdf->MultiCell(100, 3, $outputlangs->transnoentities("DeliveryOrder")." ".$outputlangs->convToOutputCharset($object->ref), '', 'R');

		$pdf->SetFont('','',$default_font_size + 2);

		$posy+=5;
		$pdf->SetXY($posx,$posy);
		$pdf->SetTextColor(0,0,60);
		if ($object->date_valid)
		{
			$pdf->MultiCell(100, 4, $outputlangs->transnoentities("Date")." : " . dol_print_date($object->date_delivery,"%d %b %Y",false,$outputlangs,true), '', 'R');
		}
		else
		{
			$pdf->SetTextColor(255,0,0);
			$pdf->MultiCell(100, 4, $outputlangs->transnoentities("DeliveryNotValidated"), '', 'R');
			$pdf->SetTextColor(0,0,60);
		}

		if ($object->thirdparty->code_client)
		{
			$posy+=5;
			$pdf->SetXY($posx,$posy);
			$pdf->SetTextColor(0,0,60);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("CustomerCode")." : " . $outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
		}

		$pdf->SetTextColor(0,0,60);

		$posy+=2;

		// Add list of linked orders on shipment
		// Currently not supported by pdf_writeLinkedObjects, link for delivery to order is done through shipment)
		if ($object->origin == 'expedition' || $object->origin == 'shipping')
		{
			$Yoff=$posy-5;

			include_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
			$shipment = new Expedition($this->db);
			$shipment->fetch($object->origin_id);

		    $origin 	= $shipment->origin;
			$origin_id 	= $shipment->origin_id;

			if ($conf->$origin->enabled)
			{
				$outputlangs->load('orders');

				$classname = ucfirst($origin);
				$linkedobject = new $classname($this->db);
				$result=$linkedobject->fetch($origin_id);
				if ($result >= 0)
				{
					$pdf->SetFont('','', $default_font_size - 2);
					$text=$linkedobject->ref;
					if ($linkedobject->ref_client) $text.=' ('.$linkedobject->ref_client.')';
					$Yoff = $Yoff+8;
					$pdf->SetXY($this->page_largeur - $this->marge_droite - 200,$Yoff);
					$pdf->MultiCell(200, 2, $outputlangs->transnoentities("RefOrder") ." : ".$outputlangs->transnoentities($text), 0, 'R');
					$Yoff = $Yoff+3;
					$pdf->SetXY($this->page_largeur - $this->marge_droite - 100,$Yoff);
					$pdf->MultiCell(100, 2, $outputlangs->transnoentities("OrderDate")." : ".dol_print_date($linkedobject->date,"day",false,$outputlangs,true), 0, 'R');
				}
			}

			$posy=$Yoff;
		}

		// Show list of linked objects
		$posy = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, 100, 3, 'R', $default_font_size);

		if ($showaddress)
		{
			// Sender properties
			$carac_emetteur = pdf_build_address($outputlangs,$this->emetteur);

			// Show sender
			$posy=42;
			$posx=$this->marge_gauche;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->page_largeur-$this->marge_droite-80;
			$hautcadre=40;

			// Show sender frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			$pdf->MultiCell(66,5, $outputlangs->transnoentities("BillFrom").":", 0, 'L');
			$pdf->SetXY($posx,$posy);
			$pdf->SetFillColor(230,230,230);
			$pdf->MultiCell(82, $hautcadre, "", 0, 'R', 1);
			$pdf->SetTextColor(0,0,60);

			// Show sender name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B',$default_font_size);
			$pdf->MultiCell(80, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
			$posy=$pdf->getY();

			// Show sender information
			$pdf->SetXY($posx+2,$posy);
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->MultiCell(80, 4, $carac_emetteur, 0, 'L');

			// Client destinataire
			$posy=42;
			$posx=102;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->marge_gauche;
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx,$posy-5);
			$pdf->MultiCell(80,5, $outputlangs->transnoentities("DeliveryAddress").":", 0, 'L');

			// If SHIPPING contact defined on order, we use it
			$usecontact=false;
			$arrayidcontact=$object->commande->getIdContact('external','SHIPPING');
			if (count($arrayidcontact) > 0)
			{
				$usecontact=true;
				$result=$object->fetch_contact($arrayidcontact[0]);
			}

			//Recipient name
			// On peut utiliser le nom de la societe du contact
			if ($usecontact && !empty($conf->global->MAIN_USE_COMPANY_NAME_OF_CONTACT)) {
				$thirdparty = $object->contact;
			} else {
				$thirdparty = $object->thirdparty;
			}

			$carac_client_name= pdfBuildThirdpartyName($thirdparty, $outputlangs);

			$carac_client=pdf_build_address($outputlangs,$this->emetteur,$object->thirdparty,($usecontact?$object->contact:''),$usecontact,'target',$object);

			// Show recipient
			$widthrecbox=100;
			if ($this->page_largeur < 210) $widthrecbox=84;	// To work with US executive format
			$posy=42;
			$posx=$this->page_largeur-$this->marge_droite-$widthrecbox;
			if (! empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) $posx=$this->marge_gauche;

			// Show recipient frame
			$pdf->SetTextColor(0,0,0);
			$pdf->SetFont('','', $default_font_size - 2);
			$pdf->SetXY($posx+2,$posy-5);
			//$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillTo").":",0,'L');
			$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre);

			// Show recipient name
			$pdf->SetXY($posx+2,$posy+3);
			$pdf->SetFont('','B', $default_font_size);
			$pdf->MultiCell($widthrecbox, 4, $carac_client_name, 0, 'L');

			$posy = $pdf->getY();

			// Show recipient information
			$pdf->SetFont('','', $default_font_size - 1);
			$pdf->SetXY($posx+2,$posy);
			$pdf->MultiCell($widthrecbox, 4, $carac_client, 0, 'L');
		}

		$pdf->SetTextColor(0,0,60);
	}

	/**
	 *   	Show footer of page. Need this->emetteur object
     *
	 *   	@param	PDF			$pdf     			PDF
	 * 		@param	Object		$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @return	int								Return height of bottom margin including footer text
	 */
	function _pagefoot(&$pdf,$object,$outputlangs,$hidefreetext=0)
	{
		global $conf;
		$showdetails=$conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS;
		return pdf_pagefoot($pdf,$outputlangs,'DELIVERY_FREE_TEXT',$this->emetteur,$this->marge_basse,$this->marge_gauche,$this->page_hauteur,$object,$showdetails,$hidefreetext);
	}

}

