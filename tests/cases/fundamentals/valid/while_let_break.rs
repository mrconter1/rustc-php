// exit: 0
// stdout: 1
// stdout: 2
fn main() {
    let mut opt: Option<i32> = Option::<i32>::Some(1);
    while let Some(n) = opt {
        println!("{}", n);
        if n >= 2 {
            break;
        }
        opt = Option::<i32>::Some(n + 1);
    }
}
