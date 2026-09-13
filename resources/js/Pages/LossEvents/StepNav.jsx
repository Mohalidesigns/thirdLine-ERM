/** The three-step indicator shared by the report and amend forms. */
export default function StepNav({ steps, step, onSelect, errorsInStep }) {
    return (
        <div className="flex flex-wrap items-center gap-3 mb-6">
            {steps.map((info, index) => {
                const active = info.step === step;
                const failing = errorsInStep(info.step) > 0;

                return (
                    <div key={info.step} className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => onSelect(info.step)}
                            className="flex items-center gap-3 text-left"
                        >
                            <span
                                className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition-all ${
                                    failing
                                        ? 'bg-red-100 text-red-700'
                                        : active
                                            ? 'bg-[#1A365D] text-white'
                                            : 'bg-gray-100 text-gray-400'
                                }`}
                            >
                                {failing ? '!' : info.step}
                            </span>
                            <span>
                                <span className={`block text-xs font-semibold ${active ? 'text-[#1A365D]' : 'text-gray-400'}`}>
                                    {info.label}
                                </span>
                                <span className="block text-[10px] text-gray-400">
                                    {failing ? `${errorsInStep(info.step)} to fix` : `Step ${info.step} of ${steps.length}`}
                                </span>
                            </span>
                        </button>
                        {index < steps.length - 1 && <span className="hidden sm:block w-8 h-px bg-gray-200" />}
                    </div>
                );
            })}
        </div>
    );
}
