import React, { useState } from 'react'
import { Listbox } from '@headlessui/react'
import { IconChevronDown, IconCircle, IconCircleFilled } from '@tabler/icons-react'

export default function InputSelect({ selected, data, setSelected, label, errors, placeholder, multiple = false, searchable = false, displayKey = 'name' }) {
    const [search, setSearch] = useState('')

    const filteredData = data.filter(item =>
        item[displayKey]?.toLowerCase().includes(search.toLowerCase())
    )

    return (
        <div className='flex flex-col gap-2'>
            {label && <label className='text-sm font-medium text-slate-700 dark:text-slate-300'>{label}</label>}
            <Listbox value={selected} onChange={setSelected} multiple={multiple} by="id">
                {/* Same box as Input.jsx (h-11, rounded-xl, slate-50) so it lines up with the inputs beside it. */}
                <Listbox.Button className={'w-full h-11 px-4 border text-sm rounded-xl flex justify-between items-center gap-2 bg-slate-50 text-slate-800 border-slate-200 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700'}>
                    <span className='truncate'>
                        {multiple ? (
                            selected.length > 0 ? selected.map(item => item[displayKey]).join(', ') : placeholder
                        ) : (
                            selected ? selected[displayKey] : placeholder
                        )}
                    </span>
                    <IconChevronDown size={20} strokeWidth={1.5} className='shrink-0' />
                </Listbox.Button>
                <Listbox.Options className={'p-4 border rounded-lg flex flex-col gap-2 bg-gray-100 dark:border-gray-900 dark:bg-gray-950'}>
                    {searchable && (
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search..."
                            className="w-full px-3 py-1.5 mb-2 text-sm border rounded-md bg-white text-gray-700 border-gray-200 focus:outline-none focus:border-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-800 dark:focus:border-gray-700"
                        />
                    )}
                    {filteredData.map((item) => (
                        <Listbox.Option key={item.id} value={item}>
                            {({ selected }) => (
                                <div
                                    className='text-sm cursor-pointer px-3 py-1.5 rounded-lg flex items-center gap-2 bg-white text-gray-700 hover:bg-gray-200 border dark:bg-gray-900 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-gray-800 '>
                                    {selected ? <IconCircleFilled size={15} strokeWidth={1.5} className='text-teal-500' /> : <IconCircle size={15} strokeWidth={1.5} />}
                                    {item[displayKey]}
                                </div>
                            )}
                        </Listbox.Option>
                    ))}
                </Listbox.Options>
            </Listbox>
            {errors && (
                <small className='text-xs text-red-500'>{errors}</small>
            )}
        </div>
    )
}
