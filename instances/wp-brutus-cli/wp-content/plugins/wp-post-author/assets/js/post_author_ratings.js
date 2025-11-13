import ReactDOM from 'react-dom'
import ReactStars from 'react-stars'
import { transitions, positions, Provider as AlertProvider } from 'react-alert'

document.addEventListener('DOMContentLoaded', () => {
    var root_id = "awpa-author-post-star-variation"
    var elements = document.getElementsByClassName(root_id)
    const DecimalNumber = ({ value, decimalPlaces }) => {
        const formattedNumber = parseFloat(value).toFixed(decimalPlaces);

        return (

            <span className='rating-avg'>{formattedNumber}</span>

        );
    };

    if (elements) {
        for (let index = 0; index < elements.length; index++) {
            const element = elements[index];
            var avg = element.getAttribute('attributes')

            let post_rating_type = element.getAttribute('rating_type')
            let rating_color_back = element.getAttribute('rating_color_back')
            let rating_color_front = element.getAttribute('rating_color_front')
            let show_avg = element.getAttribute('show_avg')
            let count = element.getAttribute('count')
            let show_votes = element.getAttribute('show_votes')
            ReactDOM.render(<AlertProvider>

                <ReactStars className="awpa-pro-rating-review "
                    key={Math.random() * 100000}
                    count={post_rating_type}
                    value={avg}
                    size={20}
                    half={true}
                    edit={false}
                    color1={rating_color_back}
                    color2={rating_color_front}
                />
                {show_avg &&
                    <DecimalNumber value={avg} decimalPlaces={1} />
                }

                <span className='rating-votes'> {Boolean(show_votes) ? count + ' reviews' : ''}</span>




            </AlertProvider >, element)
        }

    }

})