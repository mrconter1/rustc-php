// exit: 0
// stdout: 7

fn main() {
    let mut opt: Option<i32> = Option::<i32>::None;
    let mut result: i32 = 0;
    loop {
        opt = Option::<i32>::Some(7);
        match opt {
            Option::<i32>::Some(v) => {
                result = v;
                opt = Option::<i32>::None;
                break;
            }
            Option::<i32>::None => { break; }
        }
    }
    println!("{}", result);
}
