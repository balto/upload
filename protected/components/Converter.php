<?php
/**
 * Created by JetBrains PhpStorm.
 * User: balazs
 * Date: 9/14/13
 * Time: 12:37 PM
 * To change this template use File | Settings | File Templates.
 */

class Converter
{
    /** A graphicsmagick konvertalo program. */
    const PROGRAM_GM_CONVERT = 'gm convert';
    /** A graphicsmagick modosito program. */
    const PROGRAM_GM_MOGRIFY = 'gm mogrify';
    /** Az imagemagick konvertalo program. */
    const PROGRAM_IM_CONVERT = 'convert';
    /** A imagemagick modosito program. */
    const PROGRAM_IM_MOGRIFY = 'mogrify';
    /** Az kepazonosito program. */
    const PROGRAM_IDENTIFY = 'gm identify';
    /** A jpeg optimalizalo program. */
    const PROGRAM_JPEGOPTIM = 'jpegoptim';
    /** A graphicsmagick konvertalo program. */
    const PROGRAM_JPEGTRAN = 'jpegtran';

    /** Az uj kepmeretnel minden dimenzio ferjen be a megadottba es a maradekot kitoltse hatterrel. */
    const MODE_PAD  = 'pad';
    /** Az uj kepmeretnel 1 dimenzio ferjen be a megadottba es levagja a kilogokat. */
    const MODE_CROP = 'crop';
    /** Az uj kepmeretnel minden dimenzio ferjen be a megadottba es tartsa meg az eredeti keparanyt.  */
    const MODE_FIT = 'fit';

    /** @var string   A munkakonyvtar, ahova a kulonbozo atmeneti file-ok kerulnek konvertalas soran. */
    private $tempDir = 'tmp/';

    /** @var string   A kep formatuma (JPEG, GIF, stb) */
    private $imageFormat;

    /** @var string   Ha tobb framebol all a kep, akkor az elsonek az indexelese. */
    private $frameIndex;

    /**
     * A logolo objektum.
     *
     * @var LoggerSyslog
     */
   // private $logger = null;

    /**
     * Konstruktor.
     *
     * @param string $tempDir   A munkakonyvtar, ahova a kulonbozo atmeneti file-ok kerulnek a programok
     *                          futtatasa soran.
     */
    public function __construct($tempDir = '')
    {
        $this->tempDir = rtrim($tempDir, '/') . '/';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }

        //$this->logger = new LoggerSyslog('gallery_convert');
    }

    /**
     * Egy ShellCommand objektumot keszit elo a futtatasra.
     *
     * @param string $command   A futtatando parancs.
     *
     * @return ShellCommand   Az elokeszitett ShellCommand objektum.
     */
    private function getCommand($command)
    {
        return ShellCommand::create($command);
    }

    /**
     * Visszadja az adott kep felbontasat.
     *
     * @param string $imageFile   A kepfile eleresi utja.
     *
     * @return string   A kep felbontasa <width>x<height> formaban.
     */
    public function getImageSize($imageFile)
    {
        $imageInfo = getimagesize($imageFile);

        return $imageInfo[0] . 'x' . $imageInfo[1];
    }

    /**
     * Betolti a kep formatumat es az elso frame indexeleset ha van.
     *
     * @param string $file   A kep file.
     *
     * @return void
     */
    private function loadImageFormatParams($file)
    {
        $matches = array();

        $imageInfo = $this->getCommand(self::PROGRAM_IDENTIFY)
            ->addOutputMode(ShellCommand::OUTPUT_VAR)
            ->addParam(null, $file)
            ->run();

        $filename = substr($file, strrpos($file, '/') + 1);

        preg_match('/' . $filename . '(?P<firstFrame>\[\d+\])?\s+(?P<type>[\d\w]+)\s+/xi',
            $imageInfo->output, $matches);

        $this->imageFormat = $matches['type'];
        $this->frameIndex = $matches['firstFrame'];
    }

    /**
     * Visszaadja a kep formatumat.
     *
     * @param string $file   A kep file.
     *
     * @return string   A kep formatuma nagybetuvel. (pl.: JPEG, GIF, PNG, TIFF)
     */
    private function getImageFormat($file)
    {
        if (!isset($this->imageFormat)) {
            $this->loadImageFormatParams($file);
        }

        return $this->imageFormat;
    }

    /**
     * Visszaadja az elso frame indexeleset ha tobb framebol all a kep (pl. GIF animacio).
     *
     * @param string $file   A kep file.
     *
     * @return string   Az indexeles. (tipikusan: '[0]')
     */
    private function getImageFrameIndex($file)
    {

        if (!isset($this->frameParam)) {
            $this->loadImageFormatParams($file);
        }

        return $this->frameIndex;
    }

    /**
     * Visszadja hogy a kepnek a formatuma alapjan lehet-e atlatszo osszetevoje.
     *
     * @param string $file   A kep eleresi utja.
     *
     * @return bool   Ha a kep olyan formatumu ami lehet atlatszo TRUE, egyebkent FALSE..
     */
    private function isPossibleTransparentImage($file)
    {
        $transparentFormats = array(
            'PNG',
            'GIF',
            'JP2',
            'TIFF'
        );

        return in_array($this->getImageFormat($file), $transparentFormats);
    }

    /**
     * Visszaadja hogy az adott fileban lehetseges-e egyaltalan, hogy talaljunk exif informaciot.
     *
     * @param string $file   A kepfile.
     *
     * @return bool   Ha a kepformatum tartalmazhat exif-et akkor TRUE, egyebkent FALSE.
     */
    private function isPossibleExif($file)
    {
        return in_array(strtoupper($this->getImageFormat($file)), array('JPEG', 'TIFF'));
    }

    /**
     * Visszaadja a kepben tarolt exif informaciokat.
     *
     * @param string $file   A kepfile.
     *
     * @return array   Ha van exif a kepben akkor az exif informaciokat tartalmazo asszociativ tomb,
     *                 ha nincs akkor ures tomb.
     */
    public function getExifInfo($file)
    {
        if ($this->isPossibleExif($file)) {
            $exif = @exif_read_data($file);
            if (!empty($exif)) {
                return $exif;
            }
        }

        return array();
    }

    /**
     * A bejovo adatok alapjan kiszamolja hogy mekorrara kell meretezni a kepet mielott cropolnank vagy paddolnank.
     *
     * @param string $inputFile         A konvertalando kepfile.
     * @param int    $requestedWidth    A celszelesseg.
     * @param int    $requestedHeight   A celmagassag.
     * @param int    $upScalable        Engedelyezett-e a nagyitas.
     * @param string $cropMode          A vegleges kep kialakitasanak modja. (crop, pad, fit)
     *
     * @return array   A keletkezo kep szelessege es magassaga, az aranyokat megtartva NEM egeszre kerekitve.
     */
    private function getNewSize($inputFile, $requestedWidth, $requestedHeight, $upScalable, $cropMode)
    {
        list($originalWidth, $originalHeight) = getimagesize($inputFile);

        $originalAspect = $originalWidth / $originalHeight;
        $requestedAspect = $requestedWidth / $requestedHeight;

        $width  = $originalWidth;
        $height = $originalHeight;

        switch ($cropMode) {
            case self::MODE_CROP:
                // Ha nem upScalable es a kep kisebb akkor nem kell valtoztatni a mereten
                if ($upScalable == 0 && ($originalHeight < $requestedHeight || $originalWidth < $requestedWidth)) {
                    break;
                }
                // Szelesebb (aranyaiban) az eredeti video.
                if ($originalAspect > $requestedAspect) {
                    $width = ($requestedHeight * $originalAspect);
                    $height = $requestedHeight;
                }
                // Magasabb (aranyaiban) az eredeti video.
                elseif ($originalAspect <= $requestedAspect) {
                    $height = ($requestedWidth / $originalAspect);
                    $width = $requestedWidth;
                }
                break;
            case self::MODE_PAD:
            case self::MODE_FIT:
                // Ha nem upScalable es a kep kisebb akkor nem kell valtoztatni a mereten
                if ($upScalable == 0 && $originalHeight < $requestedHeight && $originalWidth < $requestedWidth) {
                    break;
                }
                // Szelesebb (aranyaiban) az eredeti video.
                if ($originalAspect > $requestedAspect) {
                    $height = ($requestedWidth / $originalAspect);
                    $width = $requestedWidth;
                }
                // Magasabb (aranyaiban) az eredeti video.
                elseif ($originalAspect <= $requestedAspect) {
                    $width = ($requestedHeight * $originalAspect);
                    $height = $requestedHeight;
                }
            default:
                break;
        }

        return array($width, $height);
    }

    /**
     * A bejovo adatok alapjan kiszamolja a crop es border parametereket.
     *
     * @param float  $width             A kiindulasi szelesseg.
     * @param float  $height            A kiindulasi magassag.
     * @param int    $requestedWidth    A celszelesseg.
     * @param int    $requestedHeight   A celmagassag.
     * @param string $cropMode          A vegleges kep kialakitasanak modja. (crop, pad, fit)
     *
     * @return array   Az elso elem a crop parametere a masodik a bordere.
     */
    private function getCropAndBorder($width, $height, $requestedWidth, $requestedHeight, $cropMode)
    {
        $borderWidth = 0;
        $borderHeight = 0;

        if ($cropMode == self::MODE_PAD) {
            if ($width < $requestedWidth) {
                $borderWidth = ceil(($requestedWidth - $width) / 2);
                $width += 2 * $borderWidth;
            }
            if ($height < $requestedHeight) {
                $borderHeight = ceil(($requestedHeight - $height) / 2);
                $height += 2 * $borderHeight;
            }
        }

        $cropWidth = $width;
        $cropHeight = $height;
        $cropOffsetX = 0;
        $cropOffsetY = 0;

        if ($width > $requestedWidth) {
            $cropWidth = $requestedWidth;
            $cropOffsetX = floor(($width - $requestedWidth) / 2);
        }
        if ($height > $requestedHeight) {
            $cropHeight = $requestedHeight;
            // TODO: Az uj croppolasi technika miatt egyelore a felso reszet croppoljuk a kepeknek. [trendes]
            $cropOffsetY = 0; //floor(($height - $requestedHeight) / 2);
        }

        return array($cropWidth . 'x' . $cropHeight . '+' . $cropOffsetX . '+' . $cropOffsetY,
            $borderWidth . 'x' . $borderHeight);
    }

    /**
     * Kep konvertalasa.
     *
     * @param string $inputFile    A forrasfile.
     * @param string $outputFile   A celfile.
     * @param array  $job          A konvertalas parameterei.
     * @param int    $imageId      A kep azonositoszama.
     *
     * @return bool   TRUE ha sikerult a konvertalas, egyebkent FALSE.
     */
    public function convert($inputFile, $outputFile, $job, $imageId = null)
    {
        // Ha a kep atlatszo akkor ImageMagicket hasznalunk, egyebkent GraphicsMagicket
        if ($this->isPossibleTransparentImage($inputFile)) {
            $convert = $this->getCommand(self::PROGRAM_IM_CONVERT);
        }
        else {
            $convert = $this->getCommand(self::PROGRAM_GM_CONVERT);
        }

        // Meghatarozzuk a konvertalas minosegi parameteret.
        switch ($job['outputFormat']) {
            case 'jpg':
                // TODO: A quality-t majd ugy kell meghatarozni hogy min(eredeti fajl minosege, job minosege)
                $convert->addParam('-quality', $job['quality']);
                break;

            case 'png':
                $convert->addParam('-quality', 9);

            default:
                break;
        }

        $convert->addParam('-background', '#' . $job['backgroundColor']);

        // Ha nem eredeti meretet konvertalunk,
        // akkor meghatarozzuk milyen parameterekkel kell ujrameretezni az eredeti kepet.
        if ($job['keep_original_size'] == 0) {
            list($newWidth, $newHeight) = $this->getNewSize($inputFile, $job['width'], $job['height'],
                $job['upScalable'], $job['cropMode']);

            $geometry = ceil($newWidth) . 'x' . ceil($newHeight) . ($job['upScalable'] == 0 ? '>' : '^');

            list($crop, $border) = $this->getCropAndBorder(round($newWidth), round($newHeight),
                $job['width'], $job['height'], $job['cropMode']);

            $convert->addParam('-geometry', $geometry)
                ->addParam('-bordercolor', '#' . $job['backgroundColor'])
                ->addParam('-border', $border)
                ->addParam('-crop', $crop);

            //Convert-nek szuksege van egy plusz paramtere
            if ($this->isPossibleTransparentImage($inputFile)) {
                $convert->addParam('+repage');
            }
        }

        if (!empty($job['blur'])) {
            $convert->addParam('-blur', $job['blur']);
        }

        $fileNameMiddle = empty($imageId) ? '' : $imageId;
        $fileNameEnd    = empty($job['filenamePostfix']) ? '' : $job['filenamePostfix'];

        $fileName =
            $job['outputFormat'] == 'jpg'
                ? $this->tempDir . ('firstStepResult' . $fileNameMiddle . $fileNameEnd . '.jpg')
                : $outputFile;

        $convert->addParam(null, $inputFile . $this->frameIndex)
            ->addParam(null, $fileName);

        $output = $convert->run();

        // JPEG kepek progressivere tomoritese es jpegtran optimalizacioja.
        if ($job['outputFormat'] == 'jpg') {
            if (file_exists($fileName)) {
                $jpegTran = $this->getCommand(self::PROGRAM_JPEGTRAN);
                $jpegTran->addParam('-progressive')
                    ->addParam('-optimize')
                    ->addParam('-copy', 'none')
                    ->addParam($fileName . ' > ' . $outputFile);

                $jpegTran->run();

                unlink($fileName);
            }
            else {
                trigger_error('Nem jott letre a konvertalas masodik lepcsojehez szukseges fajl!', E_USER_NOTICE);
            }
        }

        // Kiszamitjuk (ujra) a legeneralt kep meretett
        if ($job['keep_original_size'] == 0) {
            list($width, $height) = $this->getNewSize($inputFile, $job['width'], $job['height'],
                $job['upScalable'], $job['cropMode']);
        }
        else {
            list($width, $height) = getimagesize($inputFile);
        }

        $size = ($width > 300 && $height > 300)
            ? 'normal'
            : 'small';

        // Vizjel hozzaadasa ha szukseges
        /*if (!empty($job['waterMark']) && $job['waterMark'] == 1) {
            //Lekerjuk adatbazisbol a vizjelet
            $query = '
                SELECT
                    image_data,
                    image_name
                FROM
                    watermark
                WHERE
                    name = :_name
                    AND size = :_size
            ';
            // Jelenleg egy fajta vizjel van
            $result = Db::getInstance()->getConnection('mysql.gallery', DB::TYPE_READ_ONLY)->
                query($query, array('name' => 'default', 'size' => $size))->fetchRow();

            // Lementjuk a vizjelet a tmp konyvtarba az adatbazisban tarolt neven
            $watermarkFile = $this->tempDir . $result['image_name'];
            file_put_contents($watermarkFile, $result['image_data']);

            //Vizjel merete
            list($drawFileWidth, $drawFileHeight) = getimagesize($watermarkFile);

            $drawZeroX = ceil($width - $drawFileWidth);
            $drawZeroY = ceil($height - $drawFileHeight);

            if ($drawFileWidth <= $width && $drawFileHeight <= $height) {
                // Ha a kep atlatszo akkor ImageMagicket hasznalunk, egyebkent GraphicsMagicket
                if ($this->isPossibleTransparentImage($inputFile)) {
                    $waterMark = $this->getCommand(self::PROGRAM_IM_CONVERT);
                }
                else {
                    $waterMark = $this->getCommand(self::PROGRAM_GM_CONVERT);
                }
                $waterMark->addParam('-draw', 'image Over '
                    // Kezdo x es y pozicio
                    . $drawZeroX . ',' . $drawZeroY . ' '
                    // Szelesseg magassag
                    . $drawFileWidth . ',' . $drawFileHeight . ' '
                    // Vizjel eleresi utja (ImageMagick miatt kell idezojelbe tenni!)
                    . '"' . $watermarkFile .'"'
                )
                    ->addParam(null, $outputFile)
                    ->addParam(null, $outputFile)
                    ->run();
            }
        }*/


        // Csak akkor sikeres a konvertalas ha:
        //     - Letrejott a file
        //     - A letrejott file nem ures
        //     - Nem tortent hiba
        if (is_file($outputFile)
            && (0 < filesize($outputFile))
            && ($output->code == 0)
        ) {
            return true;
        }

        trigger_error('Nem sikerult a kep konvertalasa!', E_USER_NOTICE);
        return false;
    }

    /**
     * A megkapott exif informacio alapjan forgatja illetve tukrozi a kepet
     *
     * @param string $file          A file eleresi utja a szerveren.
     * @param int    $orientation   Az adott kep exifben tarolt elhelyezkedes (forgatas/tukrozes) azonositoja
     *                              Kivant muvelet, hogy a megfelelo kepet kapjuk:
     *                               - 0: Nincs orientationra vonatkozo exif informacio
     *                               - 1: Van informacio, de nincsen forgatva, tukrozve
     *                               - 2: Horizontalis tukrozes
     *                               - 3: forgatas 180 fokkal
     *                               - 4: tukrozes vertikalisan
     *                               - 5: forgatas 90 fokkal es tukrozve horizontalisan
     *                               - 6: forgatas 90 fokkal
     *                               - 7: forgatas 270 fokkal
     *                              http://sylvana.net/jpegcrop/exif_orientation.html
     *
     * @return bool   Ha nem kellett forgatni, vagy sikerult a forgatas, akkor true, egyebkent false
     */
    public function autoOrientByExif($file, $orientation)
    {
        $orientParams = array();

        switch ($orientation) {
            case 0:
            case 1:
                return true;
                break;

            case 2:
                $orientParams = array('-rotate' => 0, '-flop' => null);
                break;

            case 3:
                $orientParams = array('-rotate' => 180);
                break;

            case 4:
                $orientParams = array('-flip' => null);
                break;

            case 5:
                $orientParams = array('-rotate' => 90, '-flop' => null);
                break;

            case 6:
                $orientParams = array('-rotate' => 90);
                break;

            case 7:
                $orientParams = array('-rotate' => 90, '-flip' => null);
                break;

            case 8:
                $orientParams = array('-rotate' => 270);
                break;

            default:
                $orientParams = array();
        }

        $mogrify = $this->getCommand(self::PROGRAM_GM_MOGRIFY);

        foreach ($orientParams as $orientParam => $value) {
            $mogrify->addParam($orientParam, $value);
        }

        $output = $mogrify->addParam($file, null)->run();

        /*if ($output->code != 0) {
            $this->logger->addRows($output->output);
            $this->logger->send();
        }*/

        return is_file($file)
        && (0 < filesize($file))
        && ($output->code == 0);
    }

    /**
     * Kotegelt modositast vegez az adott kepen
     *
     * @param string $file    A fajl eleresi utja.
     * @param array  $batch   A kapcsolokat es azok parametereit tartalmazo tomb
     *
     * @return bool    Ha sikeresen elvegezte a modositasokat.
     */
    public function batchProcess($file, $batch)
    {
        if (empty($batch)) {
            throw new ConvertException('Nincsen feldolgozando parameter.');
        }
        if ($this->isPossibleTransparentImage($file)) {
            $mogrify = $this->getCommand(self::PROGRAM_GM_MOGRIFY);
        }
        else {
            $mogrify = $this->getCommand(self::PROGRAM_IM_MOGRIFY);
        }
        // @TODO A beerkezo commandokat es azok paramjait validalni kell!!
        foreach ($batch as $process) {
            if (in_array($process['command'], array('resize', 'rotate', 'crop', 'flip', 'flop'))) {
                $mogrify->addParam(
                    '-' . $process['command'],
                    isset($process['param']) && !empty($process['param']) ? $process['param'] : null
                );
            }
        }

        $output = $mogrify->addParam($file)->run();

       /* if ($output->code != 0) {
            $this->logger->addRows($output->output);
            $this->logger->send();
        }*/

        if (!(is_file($file) && 0 < filesize($file) && $output->code == 0)) {
            throw new ConvertException('Nem megfelelo a kimeneti file');
        }
    }

    /**
     * Masolatot keszit az eredeti filebol a temp konyvtarba
     *
     * @param string $directory   A fajlt tartalmazo konyvtar.
     * @param string $file        A fajl neve.
     *
     * @return bool    Ha sikerult a masolas true, egyebkent false.
     */
    public function backupOriginalFile($directory, $file)
    {
        $originalFile = $directory . $file;
        $tempFile     = $this->tempDir . $file;

        if (is_file($originalFile) && copy($originalFile, $tempFile)) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Kitorli a tempbol az adott nevu filet.
     *
     * @param string $filename   A file neve.
     *
     * @return bool   Ha sikerult torolni akkor true, ha nem sikerult, akkor false
     */
    public function deleteFileFromTemp($filename)
    {
        $tempFile = $this->tempDir . $filename;

        if (is_file($tempFile) && unlink($tempFile)) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * A temp konyvtarbol visszaallitja az eredeti file-t
     *
     * @param string $directory     A fajlt tartalmazo konyvtar.
     * @param string $oldFilename   A regi, kimentett fajl neve.
     * @param string $newFilename   A feldolgozas soran letrehozott uj file neve.
     *                              (pl. ha mas kiterjesztesu fajlra csereljuk az eredetit)
     *
     * @return bool
     */
    public function revertOriginalFile($directory, $oldFilename, $newFilename = '')
    {
        $originalFile = $directory . $oldFilename;
        $tempFile     = $this->tempDir . $oldFilename;
        $newFile      = $directory . $newFilename;

        if (!is_file($tempFile)) {
            return false;
        }

        if (!copy($tempFile, $originalFile)) {
            trigger_error('Hiba tortent az eredeti kep visszaallitasa soran.', E_USER_WARNING);
            return false;
        }

        if (!$this->deleteFileFromTemp($oldFilename)) {
            trigger_error('Hiba tortent az eredeti kep tempbol torlese soran.', E_USER_WARNING);
        }

        if ($oldFilename != $newFilename && is_file($newFile)) {
            if (!unlink($newFile)) {
                trigger_error('Hiba tortent az uj kepfile eltavolitasa soran.', E_USER_WARNING);
            }
        }

        return true;
    }

    /**
     * Kep croppolas.
     *
     * @param string $inputFile     A forrasfile.
     * @param string $outputFile    A celfile.
     * @param int    $coordinateX   A croppolashoz hasznalt x koordinata.
     * @param int    $coordinateY   A croppolashoz hasznalt y koordinata.
     * @param int    $newWidth      A croppolando szelesseg.
     * @param int    $newHeight     A croppolando magassag.
     *
     * @return bool   TRUE ha sikerult a croppolas, egyebkent FALSE.
     */
    public function crop($inputFile, $outputFile, $coordinateX, $coordinateY, $newWidth, $newHeight)
    {
        // Ha a kep atlatszo akkor ImageMagicket hasznalunk, egyebkent GraphicsMagicket
        if ($this->isPossibleTransparentImage($inputFile)) {
            $convert = $this->getCommand(self::PROGRAM_IM_CONVERT);
        }
        else {
            $convert = $this->getCommand(self::PROGRAM_GM_CONVERT);
        }

        $convert->addParam(null, $inputFile);
        $convert->addParam('-crop ', $newWidth . 'x' . $newHeight . '+' . $coordinateX . '+' . $coordinateY);
        $output = $convert->addParam(null, $outputFile)->run();
    }
}
