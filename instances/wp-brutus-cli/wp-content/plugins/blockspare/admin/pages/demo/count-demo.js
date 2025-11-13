import React, { useState, useEffect } from 'react'
const Countdemo = ({ type, data }) => {
    const [countData, setCountData] = useState('')
    const [isLoading, setISLoading] = useState(false)


    useEffect(() => {
        if (data) {
            const countData = Object.values(data)
                .filter(item => item.type === type)
                .length

            setCountData(countData)
            setISLoading(true)
        }
    }, [data, type])



    return (
        <>
            {isLoading &&
                <span>{countData}</span>
            }
        </>
    )



}

export default Countdemo