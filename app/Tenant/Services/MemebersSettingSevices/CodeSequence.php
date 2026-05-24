<?php

namespace App\Tenant\Services\MemebersSettingSevices;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Illuminate\Log\log;

class CodeSequence extends GlobalHelpers
{
    protected $type = null;

    protected $moduleTarget = null;

    protected $tableTaget = null;

    protected $datacolecetion = [];

    public function __construct($type = 'members')
    {
        $this->type = $type;
    }

    public function settingsCollection()
    {

        $this->datacolecetion = $this->collectSettings([$this->type, $this->moduleTarget, 'all-system']);

        // Log::info([$this->type, $this->moduleTarget]);
        return $this->datacolecetion;
    }

    public function saccoMemberFreeInputCode($code = null)
    {
        $freeHands = $this->getCodeSettingAction('sacco-' . $this->type . '-free-input-code');

        if ($freeHands) {

            return $code;
        }

        return $code;
    }
    function findThecustomCodePattern($code = null) {}

    function customCodeReplacements($slotPattern)
    {
        $slotPattern = str_replace(' ', '', strtolower($slotPattern));

        $replacements = [
            '{{code}}'   => $this->saccoMemberCodePrefix(),
        ];

        if (str_contains($slotPattern, '{{auto-generate}}')) {
            $rand = range(0, 100); // let generate a random number to
            shuffle($rand); 
            $code = time() . $rand[50];
            $replacements['{{auto-generate}}'] = $code;
        }
        if (str_contains($slotPattern, '{{auto-increment}}')) {
            $lastInput = DB::table($this->tableTaget)->latest('id')->first('id');
            $replacements['{{auto-increment}}'] =$this->saccoMemberCodeStrPad($lastInput->id+1);
        }
        if (str_contains($slotPattern, '{{random}}')) {
            $rand = range(0, 100); // let generate a random number to
            shuffle($rand); 
            $code = time() . $rand[50];
            $replacements['{{random}}'] = $code;
        }

        if (str_contains($slotPattern, '{{second}}')) {
            $replacements['{{second}}'] = date('s');
        }
        if (str_contains($slotPattern, '{{minute}}')) {
            $replacements['{{minute}}'] = date('i');
        }
        if (str_contains($slotPattern, '{{hour}}')) {
            $replacements['{{hour}}'] = date('H');
        }
        if (str_contains($slotPattern, '{{day}}')) {
            $replacements['{{day}}'] = date('d');
        }
        if (str_contains($slotPattern, '{{month}}')) {
            $replacements['{{month}}'] = date('m');
        }
        if (str_contains($slotPattern, '{{year}}')) {
            $replacements['{{year}}'] = date('Y');
        }


        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $slotPattern
        );
    }

    function findfieldforCodeGeneration($getTheChildren)
    {
        /// pick the field for code generation flow
        foreach ($getTheChildren as $key => $value) {

            if ($value['name'] == 'field_for_code_generation') {
                return $this->customCodeReplacements($value['action']);
            }
        }
    }

    public function saccoCustomCodePattern($key)
    {
        $check = $this->getCodeSettingAction($key, 'children-fields');
        if ($check) {
            $settings =  isset($this->datacolecetion[strtolower($key)]) ? $this->datacolecetion[strtolower($key)]['children-fields'] : null;
            return str_replace('--', '-',  $this->findfieldforCodeGeneration($settings));
        }
    }



    protected function getCodeSettingAction($key, $keyTarget = 'action')
    {
        $key = strtolower($key);

        // return $this->dataCollection[$key]['action'] ?? null;
        return isset($this->datacolecetion[strtolower($key)]) ? $this->datacolecetion[strtolower($key)][$keyTarget] : null;
    }

    public function saccoMemberCodeStrPad($number)
    {
        $getPading = $this->getCodeSettingAction('sacco-' . $this->type . '-code-str-pad');

        return str_pad($number, $getPading, '0', STR_PAD_LEFT);
    }

    public function saccoMemberCodeAutoGenerate($code)
    {
        $autoGen = $this->getCodeSettingAction('sacco-' . $this->type . '-code-auto-generate');
        if ($autoGen && strlen((string) $code) == 0) {
            $rand = range(0, 100); // let generate a random number to
            shuffle($rand);


            return time() . $rand[40];
        }

        return $code;
    }

    /**
     * if we find out that the code is null and auto generate is false
     * let force to generate one code to vaoid conflict
     * */
    public function saccoMemberForceToGenerateOne($code = null)
    {
        $autoGen = $this->saccoMemberCodeAutoGenerate($code);
        if ((int) $autoGen) {
            // / continue  with code
        } elseif (strlen((string) $code) == 0) {
            $rand = range(0, 100); // let generate a random number to
            shuffle($rand);
            //  in loop data some time it the same  let lie out the
            // / if code is null athen force to generate
            $code = time() . $rand[50];
        }

        return $code;
    }

    /**
     *  set the code prefix
     * **/
    public function saccoMemberCodePrefix()
    {
        $getSystemUserCodeset = $this->getCodeSettingAction('sacco-' . $this->type . '-code-prefix');
        $getSysteDefaultCode = $this->getCodeSettingAction('system-default-code');

        return strlen((string) $getSystemUserCodeset) > 0 ? $getSystemUserCodeset : $getSysteDefaultCode;
    }

    public function saccoMemberCodeSegmentLength($code)
    {
        $segments = $this->getCodeSettingAction('sacco-' . $this->type . '-code-segment-length');
        if ($segments > 1) {
            // if ($segments == null || $segments > 1) {
            $segments = str_split($code, $segments);
            $result = implode('-', $segments);

            return $result;
        }

        return $code;
    }

    public function saccoMemberCodeBaseOnLastInput($code)
    {
        $segments = $this->getCodeSettingAction('sacco-' . $this->type . '-code-base-on-last-input');
        if ($segments === 1 && isset($this->tableTaget) && strlen($code) == 0) {
            $lastInput = DB::table($this->tableTaget)->latest('id')->first('id');

            return $lastInput->id+1;
        }

        return $code;
    }

    /**
     *   generate the code
     * codeSequence (is free hand code is provided)
     * **/
    public function generate($code)
    {
        $this->settingsCollection();

        $key = 'sacco-' . $this->type . '-code-custom-generator';
        $check = $this->getCodeSettingAction($key);
        if ($check && strlen((string) $code) == 0) {

            return $this->ensureUnique($this->saccoCustomCodePattern($key));
        }

        $code = $this->saccoMemberFreeInputCode($code);
        $code = $this->saccoMemberCodeBaseOnLastInput($code);
        $code = $this->saccoMemberCodeAutoGenerate($code);
        // Log::info($code);
        // checker if code is null///
        $code = $this->saccoMemberForceToGenerateOne($code);
        $code = $this->saccoMemberCodeStrPad($code);
        $code = $this->saccoMemberCodeSegmentLength($code);
        $code = $this->applyCodePrefix($this->saccoMemberCodePrefix(), $code);

        return $this->ensureUnique($code);
    }

    private function applyCodePrefix(?string $prefix, string $code): string
    {
        $prefix = (string) $prefix;

        if (str_contains($prefix, '%s')) {
            return sprintf($prefix, $code);
        }

        return $prefix . $code;
    }

    private function ensureUnique(string $code): string
    {
        if (! $this->tableTaget || ! Schema::hasTable($this->tableTaget)) {
            return $code;
        }

        $column = $this->tableTaget === 'transactions' ? 'reference' : 'code';
        if (! Schema::hasColumn($this->tableTaget, $column)) {
            return $code;
        }

        $candidate = $code;
        $attempt = 0;
        while (DB::table($this->tableTaget)->where($column, $candidate)->exists()) {
            $attempt++;
            $candidate = $code . '-' . now()->format('Hisv') . '-' . random_int(1000, 9999);

            if ($attempt >= 10) {
                break;
            }
        }

        return $candidate;
    }

    /*
     @param $code
      this helps me to get the  code
    *@param $tableTaget
     *tableTaget this helps me to get the latest id of the  table tableTaget lets say members
     * moduleTarget target the  'settings_module' => "savings-accounts",
     *
     */
    public function codeSequence($code = null, $type = null, $moduleTarget = '', $tableTaget = '')
    {
        if ($type) {
            $this->type = $type;
        }
        if ($moduleTarget) {
            $this->moduleTarget = $moduleTarget;
        }
        if ($tableTaget) {
            $this->tableTaget = $tableTaget;
        }

        return $this->generate($code);
    }
}
