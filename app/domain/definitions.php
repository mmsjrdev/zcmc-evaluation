<?php
/**
 * app/domain/definitions.php
 *
 * Fixed lists that many pages need, kept in ONE place:
 *   - the 11 form statuses (label + badge colour)
 *   - the Definition of Terms printed on the form
 *   - the parameter lists of Section B (Quality) and Section C (Others)
 *
 * To rename a status or change its colour, edit the array below and nothing else.
 * The colour classes are written out in full on purpose: Tailwind only creates
 * CSS for class names it can find as complete words in your files.
 */

/**
 * Every status of a form: [ label shown on screen, Tailwind colour classes ].
 * The ORDER is the normal journey of a form, then the side branches.
 */
function status_definitions(): array
{
    return [
        'supplier_draft'     => ['Draft',                 'bg-slate-100 text-slate-700 ring-slate-300'],
        'submitted'          => ['Submitted to MMS',      'bg-sky-100 text-sky-800 ring-sky-300'],
        'with_head_nurse'    => ['With Head Nurse',       'bg-indigo-100 text-indigo-800 ring-indigo-300'],
        'pending_evaluation' => ['Pending evaluation',    'bg-amber-100 text-amber-800 ring-amber-300'],
        'pending_head'       => ['Pending Department Head', 'bg-orange-100 text-orange-800 ring-orange-300'],
        'pending_conforme'   => ['Pending Conforme',      'bg-violet-100 text-violet-800 ring-violet-300'],
        'completed'          => ['Completed',             'bg-green-100 text-green-800 ring-green-300'],
        'received'           => ['Received',              'bg-emerald-100 text-emerald-900 ring-emerald-400'],
        'returned_supplier'  => ['Returned to supplier',  'bg-rose-100 text-rose-800 ring-rose-300'],
        'returned_evaluator' => ['Returned to evaluator', 'bg-rose-100 text-rose-800 ring-rose-300'],
        'cancelled'          => ['Cancelled',             'bg-zinc-200 text-zinc-700 ring-zinc-300'],
    ];
}

/** The screen label of a status, for example 'with_head_nurse' -> 'With Head Nurse'. */
function status_label(string $status): string
{
    return status_definitions()[$status][0] ?? ucfirst(str_replace('_', ' ', $status));
}

/** The colour classes of a status badge. */
function status_classes(string $status): string
{
    return status_definitions()[$status][1] ?? 'bg-slate-100 text-slate-700 ring-slate-300';
}

/**
 * The 15 "Definition of Terms" from the printed form (Evaluation_Form.docx).
 * The wording is copied exactly as printed on the form.
 * Shape of each entry:  [ 'term' => 'Functionality', 'meaning' => 'The text printed on the form.' ]
 */
function definition_of_terms(): array
{
    return [
        ['term' => 'Quality',        'meaning' => 'It refers to the durability of the material, and can withstand the rigors of the procedures. It may also refer to the components of the material that are acceptable in a healthcare settings.'],
        ['term' => 'Functionality',  'meaning' => 'It refers to the purpose and/or function of the product that meets the desired outcome.'],
        ['term' => 'Reliability',    'meaning' => "It refers to the ability to rely on the product's performance on its intended function."],
        ['term' => 'Performance',    'meaning' => "It refers to the product's ability to perform and accomplish the intended purpose and/or function."],
        ['term' => 'Sustainability', 'meaning' => "It refers to the product's having a negative or less impact on the environment."],
        ['term' => 'Transparency',   'meaning' => 'It refers to the product or service expectation without hidden information such as providing a sample of a good quality product or brand but delivers a inferior quality or brand.'],
        ['term' => 'Convenience',    'meaning' => "It refers to the product's ability to save time and effort."],
        ['term' => 'Efficiency',     'meaning' => "It is similar to convinience but refers to the product's ability to streamline everyday process."],
        ['term' => 'Risk Reduction', 'meaning' => 'It refers to the guarantee to recall the products that have not passed quality assurance, and replace the products at the soonest possible time.'],
        ['term' => 'Safety',         'meaning' => 'It refers to the feature of the product to not cause harm to the user or those around them, and that the products conform to relevant safety regulations or standards.'],
        ['term' => 'Compatibility',  'meaning' => 'It refers to the product to work or integrate with another one such as consumables for specified brand of machines.'],
        ['term' => 'Options',        'meaning' => "It refers to the product's ability to meet the various needs of the end-users such as needle size and length."],
        ['term' => 'Control',        'meaning' => 'It refers to the products customization features or content of packages.'],
        ['term' => 'Experience',     'meaning' => 'It refers to the positive customer experience on the product or service such as training and assistance in trouble shooting.'],
        ['term' => 'Design',         'meaning' => 'It refers to how easily the end-users can use the product.'],
    ];
}

/**
 * Section B parameters (Failed Quality Testing): code => label.
 * The code is what is stored in evaluations.b_params (comma-separated).
 */
function quality_parameters(): array
{
    return [
        'functionality'         => 'Functionality',
        'reliability_durability' => 'Reliability/Durability',
        'performance'           => 'Performance',
        'convenience_efficiency' => 'Convenience/Efficiency',
        'safety_compatibility'  => 'Safety/Compatibility',
        'options_control'       => 'Options/Control',
        'experience_design'     => 'Experience/Design',
        'others'                => 'Others',
    ];
}

/**
 * Section C parameters (Others): code => label.
 * The code is what is stored in evaluations.c_params (comma-separated).
 */
function others_parameters(): array
{
    return [
        'transparency_risk_reduction' => 'Transparency/Risk Reduction',
        'delivery_time_stock'         => 'Delivery Time/Available Stock on Hand',
        'others'                      => 'Others',
    ];
}