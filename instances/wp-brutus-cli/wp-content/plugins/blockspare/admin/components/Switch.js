import React from 'react'

const Switch = ({data, handleChange}) => {
    return (
        <div className="control-wrapper blocksape-dashboard__switch-control">
            <label className="control-title" for={`blockspare-dashboard-${data.slug}`}>
                <input id={`blockspare-dashboard-${data.slug}`} onChange={(event) => handleChange(event, data.slug)} type="checkbox" checked={data.isEnabled} name={data.name} />
                <span className="switch" ></span>
            </label>
            <span className="control-description"></span>
        </div>
    )
}

export default Switch
