// exit: 0
// stdout: 1
fn main() {
    let mut o: Option<i32> = Option::<i32>::Some(1);
    while let Some(x) = o {
        println!("{}", x);
        o = Option::<i32>::None;
    }
}
