// exit: 0
// stdout: 6
fn get_opt() -> Option<i32> {
    Option::<i32>::Some(6)
}

fn main() {
    if let Some(n) = get_opt() {
        println!("{}", n);
    }
}
